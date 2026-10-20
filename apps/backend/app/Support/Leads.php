<?php

namespace App\Support;

use App\Domain\Role;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\StudyGroup;
use App\Models\User;
use App\Telegram\BotAdmin;
use App\Telegram\Texts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Arizalar: tashqaridan qabul qilish (sayt, bot), dublikatni birlashtirish, adminga xabar, o'quvchiga aylantirish. */
final class Leads
{
    public function __construct(private Settings $settings, private Enrollments $enrollments) {}

    /**
     * Sayt yoki botdan kelgan ariza. Shu raqamdan yaqinda ochilgan (hali yopilmagan) ariza bo'lsa — yangisi ochilmaydi,
     * mavjudiga tarix yozuvi qo'shiladi (ikki marta bosish, takroriy yuborish).
     *
     * @param  array{name:string, phone:string, course_id?:?int, comment?:?string, telegram_id?:?int, consent?:bool}  $data
     * @return array{lead: Lead, created: bool}
     */
    public function capture(array $data, string $source, array $meta = []): array
    {
        return DB::transaction(function () use ($data, $source, $meta) {
            $existing = $this->openDuplicate($data['phone']);
            if ($existing) {
                $existing->fill(array_filter([
                    'course_id' => $existing->course_id ?? ($data['course_id'] ?? null),
                    'telegram_id' => $existing->telegram_id ?? ($data['telegram_id'] ?? null),
                ], fn ($v) => $v !== null))->save();
                LeadActivity::create(['lead_id' => $existing->id, 'type' => 'SYSTEM', 'text' => $data['comment'] ?? null,
                    'data' => ['event' => 'duplicate', 'source' => $source, 'name' => $data['name'], 'course_id' => $data['course_id'] ?? null]]);

                return ['lead' => $existing, 'created' => false];
            }
            $lead = Lead::create([
                'name' => $data['name'], 'phone' => $data['phone'], 'course_id' => $data['course_id'] ?? null,
                'source' => $source, 'status' => Lead::NEW, 'comment' => $data['comment'] ?? null,
                'telegram_id' => $data['telegram_id'] ?? null, 'consent_at' => ($data['consent'] ?? false) ? now() : null,
                'next_contact_on' => today()->toDateString(), 'meta' => $meta ?: null,
            ]);
            LeadActivity::create(['lead_id' => $lead->id, 'type' => 'SYSTEM', 'data' => ['event' => 'created', 'source' => $source]]);

            return ['lead' => $lead, 'created' => true];
        });
    }

    public function openDuplicate(string $phone, ?int $exceptId = null): ?Lead
    {
        $days = $this->settings->int('leads.duplicate_window_days');
        if ($days <= 0) {
            return null;
        }

        return Lead::where('phone', $phone)->whereIn('status', Lead::OPEN)->where('created_at', '>=', now()->subDays($days))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->orderByDesc('id')->first();
    }

    /** Yangi ariza haqida admin chatlariga xabar (sozlama o'chiq bo'lsa — jim). */
    public function notifyAdmins(Lead $lead): void
    {
        if (! $this->settings->bool('leads.notify_admins')) {
            return;
        }
        $sources = (array) $this->settings->get('leads.sources');
        $text = app(Texts::class)->get('telegram.text.admin_new_lead', [
            'name' => $lead->name, 'phone' => $lead->phone,
            'course' => $lead->course_id ? (string) Course::whereKey($lead->course_id)->value('title') : '—',
            'source' => $sources[$lead->source] ?? $lead->source,
            'comment' => $lead->comment ? mb_substr($lead->comment, 0, 500) : '—',
            'url' => rtrim((string) $this->settings->get('site.url'), '/').'/dashboard/leads?id='.$lead->id,
        ]);
        app(BotAdmin::class)->alert($text);
    }

    /**
     * Arizani o'quvchiga aylantirish: o'quvchi (telefon bo'yicha mavjudi yoki yangisi), ixtiyoriy ota-ona va guruh — bitta qadamda.
     *
     * @param  array{first_name:string, last_name?:?string, phone:string, group_id?:?int, start_date?:?string, parent_name?:?string, parent_phone?:?string}  $data
     * @return array{student: User, parent: ?User, created: bool, joined: ?string}
     */
    public function convert(Lead $lead, array $data, int $actorId): array
    {
        if ($lead->status === Lead::ENROLLED && $lead->student_id) {
            throw ValidationException::withMessages(['lead' => "Bu ariza allaqachon o'quvchiga aylantirilgan."]);
        }
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => "Telefon raqami noto'g'ri. Masalan: 90 123 45 67"]);
        }
        $parentPhone = isset($data['parent_phone']) && trim((string) $data['parent_phone']) !== '' ? Phone::normalize($data['parent_phone']) : null;
        if (isset($data['parent_phone']) && trim((string) $data['parent_phone']) !== '' && ! $parentPhone) {
            throw ValidationException::withMessages(['parent_phone' => "Ota-ona telefoni noto'g'ri."]);
        }
        if ($parentPhone && $parentPhone === $phone) {
            throw ValidationException::withMessages(['parent_phone' => "O'quvchi va ota-onaning telefoni bir xil bo'lmasligi kerak (raqam bitta akkauntga tegishli)."]);
        }

        return DB::transaction(function () use ($lead, $data, $phone, $parentPhone, $actorId) {
            [$student, $created] = $this->findOrCreate($phone, Role::Student, $data['first_name'], $data['last_name'] ?? null, 'phone');
            $parent = null;
            if ($parentPhone) {
                [$parent] = $this->findOrCreate($parentPhone, Role::Parent, trim((string) ($data['parent_name'] ?? '')) ?: $lead->name, null, 'parent_phone');
                DB::table('parent_child')->insertOrIgnore(['parent_id' => $parent->id, 'child_id' => $student->id]);
            }
            $joined = null;
            if (! empty($data['group_id'])) {
                $joined = $this->enrollments->join(StudyGroup::findOrFail($data['group_id']), $student, $data['start_date'] ?? null, $actorId);
            }
            $from = $lead->status;
            $lead->forceFill([
                'status' => Lead::ENROLLED, 'student_id' => $student->id, 'converted_at' => now(), 'lost_reason' => null,
                'group_id' => $data['group_id'] ?? $lead->group_id, 'next_contact_on' => null,
            ])->save();
            LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $actorId, 'type' => 'STATUS',
                'data' => ['from' => $from, 'to' => Lead::ENROLLED, 'student_id' => $student->id, 'group_id' => $data['group_id'] ?? null, 'parent_id' => $parent?->id]]);

            return ['student' => $student, 'parent' => $parent, 'created' => $created, 'joined' => $joined];
        });
    }

    /** Telefon bo'yicha foydalanuvchi: mavjud bo'lsa roli mos kelishi shart, aks holda yaratiladi. @return array{0: User, 1: bool} */
    public function findOrCreate(string $phone, Role $role, string $firstName, ?string $lastName, string $field): array
    {
        $user = User::where('phone', $phone)->first();
        if ($user) {
            if ($user->role !== $role) {
                $labels = (array) $this->settings->get('telegram.role_labels');
                throw ValidationException::withMessages([$field => "Bu raqam boshqa rolga tegishli: ".($labels[$user->role->value] ?? $user->role->value).' ('.trim($user->first_name.' '.$user->last_name).').']);
            }

            return [$user, false];
        }

        return [User::create(['phone' => $phone, 'first_name' => mb_substr($firstName, 0, 64), 'last_name' => $lastName ? mb_substr($lastName, 0, 64) : null, 'role' => $role, 'active' => true]), true];
    }

    /** "Ali Valiyev" → ["Ali", "Valiyev"]. */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [''];

        return [$parts[0], $parts[1] ?? null];
    }
}
