<?php

namespace App\Support;

use App\Domain\Role;
use App\Models\BillingEntry;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O'quvchining guruhdagi a'zolik tarixi (enrollments).
 *
 * Davr (period) — guruhga qo'shilgandan chiqqungacha. Davr bir yoki bir necha ketma-ket qatordan iborat:
 *   ACTIVE [1-okt .. 9-okt] → FROZEN [10-okt .. 19-okt, "Kasallik"] → ACTIVE [20-okt .. ochiq]
 * Davr yopilganda oxirgi qatorga natija yoziladi: LEFT (sabab) / GRADUATED / TRANSFERRED (yangi guruh).
 * Qayta qo'shilish — yangi davr; eski tarix saqlanadi.
 *
 * group_members — "hozirgi a'zolik" (left_at null — guruhda; muzlatilgan ham guruhda hisoblanadi).
 * To'lov faqat ACTIVE kunlar uchun hisoblanadi (Billing::accrue).
 */
final class Enrollments
{
    public const ACTIVE = 'ACTIVE';

    public const FROZEN = 'FROZEN';

    public const LEFT = 'LEFT';

    public const GRADUATED = 'GRADUATED';

    public const TRANSFERRED = 'TRANSFERRED';

    public function __construct(private Settings $settings) {}

    // ── Yozish ───────────────────────────────────────────────

    /**
     * Guruhga qo'shish (yoki qayta qo'shish). Allaqachon a'zo bo'lsa hech narsa o'zgarmaydi.
     * Bugun xato chiqarib yuborilgan o'quvchi bugun qayta qo'shilsa — chiqarish bekor qilinadi (davr davom etadi).
     *
     * @return 'added'|'rejoined'|'restored'|'already'
     */
    public function join(StudyGroup $group, User $student, ?string $date = null, ?int $actorId = null): string
    {
        if ($student->role !== Role::Student) {
            throw ValidationException::withMessages(['student_id' => "Faqat o'quvchini guruhga qo'shish mumkin."]);
        }
        $date = $this->date($date, 'start_date', allowFuture: true);

        return DB::transaction(function () use ($group, $student, $date, $actorId) {
            $member = DB::table('group_members')->where(['group_id' => $group->id, 'student_id' => $student->id])->lockForUpdate()->first();
            if ($member && $member->left_at === null) {
                return 'already';
            }
            $this->ensureHistory($group->id, $student->id);
            if ($member && $this->undoTodaysLeave($group->id, $student->id, $date)) {
                DB::table('group_members')->where(['group_id' => $group->id, 'student_id' => $student->id])->update(['left_at' => null]);

                return 'restored';
            }
            $lastEnd = DB::table('enrollments')->where(['group_id' => $group->id, 'student_id' => $student->id])->max('ends_on');
            if ($lastEnd && substr((string) $lastEnd, 0, 10) >= $date) {
                throw ValidationException::withMessages(['start_date' => "O'quvchi bu guruhda ".$this->fmt(substr((string) $lastEnd, 0, 10))." gacha o'qigan — qayta qo'shish sanasi undan keyin bo'lishi kerak."]);
            }
            $this->insert($group->id, $student->id, self::ACTIVE, $date, null, $actorId);
            $joinedAt = $date === today()->toDateString() ? now() : Carbon::parse($date)->startOfDay();
            DB::table('group_members')->updateOrInsert(
                ['group_id' => $group->id, 'student_id' => $student->id],
                ['joined_at' => $joinedAt, 'left_at' => null],
            );

            return $member ? 'rejoined' : 'added';
        });
    }

    /**
     * Bugun "chiqib ketgan" deb yopilgan davrni qayta ochish (xato chiqarish). Faqat: oxirgi qator ACTIVE, natija LEFT,
     * yopilgan sana — bugun va qayta qo'shish sanasi bugundan keyin emas. Orqa sana bilan chiqarilganlar (to'lov qaytarilgan) — yo'q.
     */
    private function undoTodaysLeave(int $groupId, int $studentId, string $date): bool
    {
        $today = today()->toDateString();
        $last = DB::table('enrollments')->where(['group_id' => $groupId, 'student_id' => $studentId])
            ->orderByDesc('starts_on')->orderByDesc('id')->lockForUpdate()->first();
        if (! $last || $last->outcome !== self::LEFT || $last->status !== self::ACTIVE
            || substr((string) $last->ends_on, 0, 10) !== $today || $date > $today) {
            return false;
        }
        DB::table('enrollments')->where('id', $last->id)
            ->update(['ends_on' => null, 'outcome' => null, 'reason' => null, 'note' => null, 'transfer_group_id' => null, 'updated_at' => now()]);

        return true;
    }

    /** Guruhdan chiqarish yoki bitirish. $date — o'quvchi o'qigan oxirgi kun (shu kun ham hisoblanadi). */
    public function close(StudyGroup $group, User $student, string $outcome, ?string $date, ?string $reason, ?string $note = null, ?int $actorId = null, ?int $transferGroupId = null): void
    {
        $date = $this->date($date, 'date');
        if ($outcome === self::LEFT) {
            $reason = $this->reason($reason, 'enrollment.leave_reasons');
        }
        DB::transaction(function () use ($group, $student, $outcome, $date, $reason, $note, $actorId, $transferGroupId) {
            $segments = $this->openPeriod($group->id, $student->id, lock: true);
            $periodStart = $segments->first()->starts_on;
            if ($date < $periodStart) {
                throw ValidationException::withMessages(['date' => "Sana o'quvchi guruhga qo'shilgan kundan (".$this->fmt($periodStart).") oldin bo'lishi mumkin emas."]);
            }
            // Sanadan keyin boshlanadigan (rejalashtirilgan) qatorlar olib tashlanadi, sanani qamragan qator yopiladi.
            foreach ($segments as $seg) {
                if ($seg->starts_on > $date) {
                    DB::table('enrollments')->where('id', $seg->id)->delete();
                }
            }
            $last = $segments->last(fn ($s) => $s->starts_on <= $date);
            DB::table('enrollments')->where('id', $last->id)->update([
                'ends_on' => $date, 'outcome' => $outcome, 'transfer_group_id' => $transferGroupId,
                // Muzlatish sababi ustiga yozilmaydi: chiqish sababi faqat bo'sh bo'lsa yoki ACTIVE qatorga yoziladi.
                'reason' => $last->status === self::ACTIVE || $reason !== null ? ($reason ?? $last->reason) : $last->reason,
                'note' => $note ?? $last->note, 'updated_at' => now(),
            ]);
            DB::table('group_members')->where(['group_id' => $group->id, 'student_id' => $student->id])
                ->update(['left_at' => $date === today()->toDateString() ? now() : Carbon::parse($date)->endOfDay()]);
            $this->refundCharged($group, $student->id, Carbon::parse($date)->addDay()->toDateString(), null, $outcome, $actorId);
        });
    }

    /** [from..to] kunlarida muzlatish (to'lov hisoblanmaydi). Kelajakdagi muzlatishni ham rejalashtirish mumkin. */
    public function freeze(StudyGroup $group, User $student, string $from, string $to, ?string $reason, ?string $note = null, ?int $actorId = null): array
    {
        $from = $this->date($from, 'from', allowFuture: true);
        $to = $this->date($to, 'to', allowFuture: true, checkBackdate: false);
        $reason = $this->reason($reason, 'enrollment.freeze_reasons');
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => "Tugash sanasi boshlanishdan oldin bo'lishi mumkin emas."]);
        }
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $max = $this->settings->int('enrollment.max_freeze_days');
        if ($days > $max) {
            throw ValidationException::withMessages(['to' => "Muzlatish $max kundan oshmasligi kerak."]);
        }

        return DB::transaction(function () use ($group, $student, $from, $to, $reason, $note, $actorId, $days) {
            $segments = $this->openPeriod($group->id, $student->id, lock: true);
            if ($from < $segments->first()->starts_on) {
                throw ValidationException::withMessages(['from' => "Sana o'quvchi guruhga qo'shilgan kundan (".$this->fmt($segments->first()->starts_on).") oldin bo'lishi mumkin emas."]);
            }
            // [from..to] butunlay bitta ACTIVE qator ichida bo'lishi kerak (muzlatishlar ustma-ust tushmaydi).
            $seg = $segments->first(fn ($s) => $s->starts_on <= $from && ($s->ends_on === null || $s->ends_on >= $from));
            if (! $seg || $seg->status !== self::ACTIVE || ($seg->ends_on !== null && $seg->ends_on < $to)) {
                throw ValidationException::withMessages(['from' => "Bu kunlarda o'quvchi allaqachon muzlatilgan. Avvalgi muzlatishni tugating yoki boshqa sanani tanlang."]);
            }
            $now = now();
            if ($seg->starts_on < $from) {
                DB::table('enrollments')->where('id', $seg->id)->update(['ends_on' => Carbon::parse($from)->subDay()->toDateString(), 'updated_at' => $now]);
                $frozenId = $this->insert($group->id, $student->id, self::FROZEN, $from, $to, $actorId, $reason, $note);
            } else {
                DB::table('enrollments')->where('id', $seg->id)->update(['status' => self::FROZEN, 'ends_on' => $to, 'reason' => $reason, 'note' => $note, 'updated_at' => $now]);
                $frozenId = $seg->id;
            }
            if ($seg->ends_on === null || $seg->ends_on > $to) {
                $this->insert($group->id, $student->id, self::ACTIVE, Carbon::parse($to)->addDay()->toDateString(), $seg->ends_on, $actorId);
            }
            $refund = $this->refundCharged($group, $student->id, $from, $to, self::FROZEN, $actorId);

            return ['id' => $frozenId, 'days' => $days, 'refunded' => $refund];
        });
    }

    /**
     * Muzlatishni tugatish: bugundan (yoki $date dan) boshlab yana o'qiydi. Hali boshlanmagan muzlatish bekor qilinadi.
     * O'tib ketgan muzlatilgan kunlar muzlatilganligicha qoladi.
     */
    public function unfreeze(StudyGroup $group, User $student, ?string $date = null, ?int $actorId = null): void
    {
        $date = $this->date($date, 'date', allowFuture: true);
        DB::transaction(function () use ($group, $student, $date) {
            $segments = $this->openPeriod($group->id, $student->id, lock: true);
            // Sanani qamragan yoki undan keyingi eng yaqin muzlatish.
            $frozen = $segments->first(fn ($s) => $s->status === self::FROZEN && $s->ends_on >= $date);
            if (! $frozen) {
                throw ValidationException::withMessages(['date' => "O'quvchi muzlatilmagan."]);
            }
            $next = $segments->first(fn ($s) => $s->starts_on > $frozen->starts_on && $s->status === self::ACTIVE);
            $prev = $segments->last(fn ($s) => $s->starts_on < $frozen->starts_on && $s->status === self::ACTIVE);
            $now = now();
            if ($frozen->starts_on >= $date) {
                // Hali boshlanmagan — bekor: oldingi va keyingi ACTIVE qatorlar birlashadi.
                if ($prev && $prev->ends_on === Carbon::parse($frozen->starts_on)->subDay()->toDateString()) {
                    DB::table('enrollments')->where('id', $prev->id)->update(['ends_on' => $next?->ends_on, 'outcome' => $next?->outcome, 'updated_at' => $now]);
                    DB::table('enrollments')->whereIn('id', array_filter([$frozen->id, $next?->id]))->delete();
                } else {
                    DB::table('enrollments')->where('id', $frozen->id)->update(['status' => self::ACTIVE, 'ends_on' => $next?->ends_on, 'reason' => null, 'updated_at' => $now]);
                    if ($next) {
                        DB::table('enrollments')->where('id', $next->id)->delete();
                    }
                }

                return;
            }
            DB::table('enrollments')->where('id', $frozen->id)->update(['ends_on' => Carbon::parse($date)->subDay()->toDateString(), 'updated_at' => $now]);
            if ($next) {
                DB::table('enrollments')->where('id', $next->id)->update(['starts_on' => $date, 'updated_at' => $now]);
            } else {
                $this->insert($group->id, $student->id, self::ACTIVE, $date, null, null);
            }
        });
    }

    /** Boshqa guruhga ko'chirish: eski guruhdagi davr $date dan oldingi kun bilan yopiladi, yangisida $date dan ochiladi. */
    public function transfer(StudyGroup $from, StudyGroup $to, User $student, ?string $date, ?string $reason = null, ?string $note = null, ?int $actorId = null): void
    {
        $date = $this->date($date, 'date', allowFuture: false);
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_group_id' => 'Boshqa guruhni tanlang.']);
        }
        if (! $to->active) {
            throw ValidationException::withMessages(['to_group_id' => 'Bu guruh faol emas.']);
        }
        if (DB::table('group_members')->where(['group_id' => $to->id, 'student_id' => $student->id])->whereNull('left_at')->exists()) {
            throw ValidationException::withMessages(['to_group_id' => "O'quvchi bu guruhda allaqachon o'qiydi."]);
        }
        DB::transaction(function () use ($from, $to, $student, $date, $reason, $note, $actorId) {
            $start = $this->openPeriod($from->id, $student->id)->first()->starts_on;
            // Qo'shilgan kuniyoq ko'chirilsa — eski guruhda bir kun ham hisoblanmaydi (accrue shuni hisobga oladi).
            $last = $date > $start ? Carbon::parse($date)->subDay()->toDateString() : $start;
            $this->close($from, $student, self::TRANSFERRED, $last, $reason, $note, $actorId, $to->id);
            $this->join($to, $student, $date, $actorId);
        });
    }

    /** Guruhning hozirgi barcha a'zolarini bitirgan deb belgilash. @return int bitirganlar soni */
    public function graduateGroup(StudyGroup $group, ?string $date, ?int $actorId = null): int
    {
        $ids = DB::table('group_members')->where('group_id', $group->id)->whereNull('left_at')->pluck('student_id');
        $n = 0;
        DB::transaction(function () use ($group, $ids, $date, $actorId, &$n) {
            foreach (User::whereIn('id', $ids)->get() as $student) {
                $this->close($group, $student, self::GRADUATED, $date, null, null, $actorId);
                $n++;
            }
        });

        return $n;
    }

    // ── O'qish ───────────────────────────────────────────────

    /**
     * Guruhning hozirgi a'zolari holati (bugungi kun bo'yicha).
     *
     * @return array<int, array{status:string, since:string, until:?string, reason:?string, frozen_from:?string, frozen_to:?string}>
     */
    public function currentStatuses(int $groupId, ?array $studentIds = null): array
    {
        $today = today()->toDateString();
        $members = DB::table('group_members')->where('group_id', $groupId)->whereNull('left_at')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->get(['student_id', 'joined_at']);
        $rows = DB::table('enrollments')->where('group_id', $groupId)->whereIn('student_id', $members->pluck('student_id'))
            ->whereNull('outcome')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('starts_on')->get()->groupBy('student_id');
        $out = [];
        foreach ($members as $m) {
            $segs = ($rows[$m->student_id] ?? collect())->map(fn ($r) => $this->row($r));
            $now = $segs->first(fn ($s) => $s->starts_on <= $today && ($s->ends_on === null || $s->ends_on >= $today));
            $plannedFreeze = $segs->first(fn ($s) => $s->status === self::FROZEN && $s->ends_on >= $today);
            $out[(int) $m->student_id] = [
                'status' => $now?->status ?? self::ACTIVE,
                'since' => $now?->starts_on ?? substr((string) $m->joined_at, 0, 10),
                'until' => $now?->status === self::FROZEN ? $now->ends_on : null,
                'reason' => $now?->status === self::FROZEN ? $now->reason : null,
                'frozen_from' => $plannedFreeze?->starts_on,
                'frozen_to' => $plannedFreeze?->ends_on,
            ];
        }

        return $out;
    }

    /** Guruhdan chiqqan a'zolar (oxirgi davr natijasi bilan). */
    public function formerMembers(int $groupId): Collection
    {
        $last = DB::table('enrollments')->where('group_id', $groupId)->whereNotNull('outcome')
            ->selectRaw('student_id, max(ends_on) as ends_on')->groupBy('student_id');

        return DB::table('group_members')
            ->join('users', 'users.id', '=', 'group_members.student_id')
            ->leftJoinSub($last, 'l', 'l.student_id', '=', 'group_members.student_id')
            ->leftJoin('enrollments as e', fn ($j) => $j->on('e.student_id', '=', 'group_members.student_id')
                ->where('e.group_id', $groupId)->whereColumn('e.ends_on', 'l.ends_on')->whereNotNull('e.outcome'))
            ->leftJoin('study_groups as tg', 'tg.id', '=', 'e.transfer_group_id')
            ->where('group_members.group_id', $groupId)->whereNotNull('group_members.left_at')
            ->orderByDesc('group_members.left_at')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'group_members.joined_at', 'group_members.left_at',
                'e.outcome', 'e.reason', 'e.ends_on', 'e.transfer_group_id', 'tg.name as transfer_group_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'first_name' => $r->first_name, 'last_name' => $r->last_name,
                'joined_at' => substr((string) $r->joined_at, 0, 10), 'left_on' => $r->ends_on ? substr((string) $r->ends_on, 0, 10) : substr((string) $r->left_at, 0, 10),
                'status' => $r->outcome ?? self::LEFT, 'reason' => $r->reason,
                'transfer_group' => $r->transfer_group_id ? ['id' => (int) $r->transfer_group_id, 'name' => $r->transfer_group_name] : null,
            ]);
    }

    /** O'quvchining barcha guruhlardagi tarixi (yangilari yuqorida). */
    public function timeline(int $studentId, ?array $groupIds = null): array
    {
        $this->backfillStudent($studentId);

        return DB::table('enrollments')
            ->join('study_groups', 'study_groups.id', '=', 'enrollments.group_id')
            ->leftJoin('courses', 'courses.id', '=', 'study_groups.course_id')
            ->leftJoin('study_groups as tg', 'tg.id', '=', 'enrollments.transfer_group_id')
            ->leftJoin('users as cb', 'cb.id', '=', 'enrollments.created_by')
            ->where('enrollments.student_id', $studentId)
            ->when($groupIds !== null, fn ($q) => $q->whereIn('enrollments.group_id', $groupIds))
            ->orderByDesc('enrollments.starts_on')->orderByDesc('enrollments.id')
            ->get(['enrollments.*', 'study_groups.name as group_name', 'courses.title as course_title', 'tg.name as transfer_group_name',
                'cb.first_name as created_by_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'group_id' => (int) $r->group_id, 'group_name' => $r->group_name, 'course_title' => $r->course_title,
                'status' => $r->status, 'starts_on' => substr((string) $r->starts_on, 0, 10), 'ends_on' => $r->ends_on ? substr((string) $r->ends_on, 0, 10) : null,
                'outcome' => $r->outcome, 'reason' => $r->reason, 'note' => $r->note,
                'transfer_group' => $r->transfer_group_id ? ['id' => (int) $r->transfer_group_id, 'name' => $r->transfer_group_name] : null,
                'created_by' => $r->created_by_name,
            ])->all();
    }

    /**
     * To'lov hisoblanadigan oraliqlar: [from..to] bilan kesishgan ACTIVE qatorlar.
     * Tarixi yo'q eski a'zoliklar (migratsiyadan oldin yoki bevosita group_members ga yozilganlar) — joined_at..left_at.
     *
     * @return Collection<int, object{group_id:int, student_id:int, from:string, to:?string, skip_same_day:bool}>
     */
    public static function chargeable(string $fromDate, string $toDate): Collection
    {
        $segments = DB::table('enrollments')
            ->where('status', self::ACTIVE)
            ->where('starts_on', '<=', $toDate)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $fromDate))
            ->get(['group_id', 'student_id', 'starts_on', 'ends_on', 'outcome'])
            ->map(fn ($e) => (object) [
                'group_id' => (int) $e->group_id, 'student_id' => (int) $e->student_id,
                'from' => substr((string) $e->starts_on, 0, 10), 'to' => $e->ends_on ? substr((string) $e->ends_on, 0, 10) : null,
                // Qo'shilgan kuniyoq chiqarilgan / ko'chirilgan (xato qo'shish) — hisoblanmaydi.
                'skip_same_day' => in_array($e->outcome, [self::LEFT, self::TRANSFERRED], true),
            ]);
        $legacy = DB::table('group_members')
            ->whereNotExists(fn ($q) => $q->from('enrollments')->whereColumn('enrollments.group_id', 'group_members.group_id')
                ->whereColumn('enrollments.student_id', 'group_members.student_id'))
            ->where('group_members.joined_at', '<=', $toDate.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('group_members.left_at')->orWhere('group_members.left_at', '>=', $fromDate.' 00:00:00'))
            ->get(['group_id', 'student_id', 'joined_at', 'left_at'])
            ->map(fn ($m) => (object) [
                'group_id' => (int) $m->group_id, 'student_id' => (int) $m->student_id,
                'from' => substr((string) $m->joined_at, 0, 10), 'to' => $m->left_at ? substr((string) $m->left_at, 0, 10) : null,
                'skip_same_day' => $m->left_at !== null,
            ]);

        // Bir kunlik qator faqat butun davr bo'lsa (oldidan muzlatish va h.k. bo'lmasa) "xato qo'shish" hisoblanadi.
        $segments = $segments->map(function ($s) {
            if ($s->skip_same_day && $s->from === $s->to) {
                $s->skip_same_day = ! DB::table('enrollments')->where(['group_id' => $s->group_id, 'student_id' => $s->student_id])
                    ->where('ends_on', Carbon::parse($s->from)->subDay()->toDateString())->whereNull('outcome')->exists();
            }

            return $s;
        });

        return $segments->concat($legacy)->values();
    }

    // ── Yordamchilar ─────────────────────────────────────────

    /** Joriy (ochiq) davr qatorlari, sana bo'yicha. A'zo bo'lmasa — 422. */
    private function openPeriod(int $groupId, int $studentId, bool $lock = false): Collection
    {
        $member = DB::table('group_members')->where(['group_id' => $groupId, 'student_id' => $studentId])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $member || $member->left_at !== null) {
            throw ValidationException::withMessages(['student_id' => "O'quvchi bu guruhda o'qimayapti."]);
        }
        $this->ensureHistory($groupId, $studentId);
        $all = DB::table('enrollments')->where(['group_id' => $groupId, 'student_id' => $studentId])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('starts_on')->orderBy('id')->get()->map(fn ($r) => $this->row($r));
        $lastClosed = $all->last(fn ($r) => $r->outcome !== null);

        return $all->filter(fn ($r) => $r->outcome === null && (! $lastClosed || $r->starts_on > $lastClosed->ends_on))->values()
            ->whenEmpty(fn () => throw ValidationException::withMessages(['student_id' => "A'zolik tarixi topilmadi."]));
    }

    /** Tarixi yo'q a'zolik uchun bitta ACTIVE qator (eski ma'lumotlar uchun xavfsizlik to'ri). */
    private function ensureHistory(int $groupId, int $studentId): void
    {
        if (DB::table('enrollments')->where(['group_id' => $groupId, 'student_id' => $studentId])->exists()) {
            return;
        }
        $m = DB::table('group_members')->where(['group_id' => $groupId, 'student_id' => $studentId])->first();
        if ($m) {
            $this->insert($groupId, $studentId, self::ACTIVE, substr((string) $m->joined_at, 0, 10),
                $m->left_at ? substr((string) $m->left_at, 0, 10) : null, null, outcome: $m->left_at ? self::LEFT : null);
        }
    }

    private function backfillStudent(int $studentId): void
    {
        foreach (DB::table('group_members')->where('student_id', $studentId)->pluck('group_id') as $groupId) {
            $this->ensureHistory((int) $groupId, $studentId);
        }
    }

    private function insert(int $groupId, int $studentId, string $status, string $from, ?string $to, ?int $actorId, ?string $reason = null, ?string $note = null, ?string $outcome = null): int
    {
        return (int) DB::table('enrollments')->insertGetId([
            'group_id' => $groupId, 'student_id' => $studentId, 'status' => $status, 'starts_on' => $from, 'ends_on' => $to,
            'outcome' => $outcome, 'reason' => $reason, 'note' => $note, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(object $r): object
    {
        $r->starts_on = substr((string) $r->starts_on, 0, 10);
        $r->ends_on = $r->ends_on ? substr((string) $r->ends_on, 0, 10) : null;

        return $r;
    }

    /**
     * Orqa sana bilan muzlatish/chiqishda shu kunlar uchun allaqachon hisoblangan summa tuzatish (ADJUSTMENT) bilan qaytariladi.
     * Kunlik hisoblash o'sha kunlarni qayta hisoblamaydi (dedupe_key), jurnal o'zgarmas qoladi.
     */
    private function refundCharged(StudyGroup $group, int $studentId, string $from, ?string $to, string $status, ?int $actorId): int
    {
        if (! $this->settings->bool('enrollment.refund_backdated')) {
            return 0;
        }
        $charges = DB::table('billing_entries')->where(['student_id' => $studentId, 'group_id' => $group->id, 'type' => BillingEntry::CHARGE])
            ->whereNull('voided_at')->where('entry_date', '>=', $from)->when($to, fn ($q) => $q->where('entry_date', '<=', $to));
        $sum = -(int) (clone $charges)->sum('amount');
        if ($sum <= 0) {
            return 0;
        }
        $days = (clone $charges)->count();
        $first = substr((string) (clone $charges)->min('entry_date'), 0, 10);
        $last = substr((string) (clone $charges)->max('entry_date'), 0, 10);
        $labels = (array) $this->settings->get('enrollment.status_labels');
        $note = strtr((string) $this->settings->get('enrollment.refund_note'), [
            '{status}' => $labels[$status] ?? $status, '{group}' => $group->name, '{from}' => $this->fmt($first), '{to}' => $this->fmt($last), '{days}' => $days,
        ]);
        BillingEntry::create([
            'student_id' => $studentId, 'group_id' => $group->id, 'type' => BillingEntry::ADJUSTMENT, 'amount' => $sum,
            'entry_date' => today()->toDateString(), 'note' => mb_substr($note, 0, 500), 'created_by' => $actorId,
            'meta' => ['enrollment' => $status, 'from' => $first, 'to' => $last, 'days' => $days],
        ]);

        return $sum;
    }

    /** Sana: bo'sh — bugun; orqa sana enrollment.max_backdate_days dan oshmaydi. */
    private function date(?string $value, string $field, bool $allowFuture = false, bool $checkBackdate = true): string
    {
        $today = today();
        try {
            $d = $value ? Carbon::parse($value)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => "Sana noto'g'ri."]);
        }
        if (! $allowFuture && $d->gt($today)) {
            throw ValidationException::withMessages([$field => "Kelajakdagi sanani tanlab bo'lmaydi."]);
        }
        $max = $this->settings->int('enrollment.max_backdate_days');
        if ($checkBackdate && $d->lt($today->copy()->subDays($max))) {
            throw ValidationException::withMessages([$field => "Sana $max kundan oldin bo'lishi mumkin emas."]);
        }

        return $d->toDateString();
    }

    private function reason(?string $reason, string $listKey): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            if ($this->settings->bool('enrollment.require_reason')) {
                throw ValidationException::withMessages(['reason' => 'Sababni tanlang.']);
            }

            return null;
        }

        return mb_substr($reason, 0, 255);
    }

    private function fmt(string $date): string
    {
        return Carbon::parse($date)->format('d.m.Y');
    }
}
