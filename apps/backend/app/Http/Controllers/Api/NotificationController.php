<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Support\Csv;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bildirishnomalar markazi (notifications_log o'qiladi):
 *  - foydalanuvchi: o'z xabarlari (qo'ng'iroqcha), o'qildi belgisi — Telegram ulanmagan bo'lsa ham xabar shu yerda ko'rinadi;
 *  - administrator (user.manage): yetkazish hisoboti turlar bo'yicha va yetib bo'lmaydigan foydalanuvchilar ro'yxati.
 * Ota-ona roli yo'q: hisobot faqat o'quvchi, o'qituvchi va administratorlarni qamraydi.
 */
class NotificationController
{
    public const STATUSES = ['sent', 'failed', 'not_linked', 'blocked', 'skipped', 'not_configured'];

    /** Hisobot va "yetib bo'lmaydiganlar" qamrovidagi rollar. */
    private const ROLES = [Role::Student, Role::Teacher, Role::Admin, Role::SuperAdmin];

    public function __construct(private Settings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['unread' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = $this->inbox($request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($this->settings->int('notifications.page_size'), ['id', 'kind', 'payload', 'sent_at', 'error', 'read_at', 'created_at']);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->present($r))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()] + $this->counts($request->user()),
        ]);
    }

    /** Qo'ng'iroqcha uchun yengil so'rov (polling). */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $this->inbox($user->id)->whereNull('read_at')->orderByDesc('id')->limit(5)
            ->get(['id', 'kind', 'payload', 'sent_at', 'error', 'read_at', 'created_at'])->map(fn ($r) => $this->present($r));

        return response()->json($this->counts($user) + ['latest' => $latest]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer'], 'all' => ['nullable', 'boolean']]);
        abort_if(empty($data['ids']) && ! $request->boolean('all'), 422, "ids yoki all kerak.");
        $user = $request->user();
        $q = DB::table('notifications_log')->where('user_id', $user->id)->whereNull('read_at')
            ->when(! $request->boolean('all'), fn ($w) => $w->whereIn('id', $data['ids']));
        // E'lon xabari o'qilsa — yangilik ham o'qilgan hisoblanadi.
        $announcements = (clone $q)->where('kind', 'like', 'announcement:%')->pluck('kind')
            ->map(fn ($k) => (int) substr($k, strlen('announcement:')))->filter()->unique();
        $updated = $q->update(['read_at' => now()]);
        foreach ($announcements as $id) {
            DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $user->id, 'read_at' => now()]);
        }

        return response()->json(['ok' => true, 'updated' => $updated] + $this->counts($user));
    }

    // ── Administrator: yetkazish hisoboti ───────────────────

    public function report(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $rows = DB::table('notifications_log')->where('channel', 'telegram')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('kind, '.self::statusSql().' as status, count(*) as c')
            ->groupBy('kind', 'status')->get();

        $byKind = [];
        $total = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $prefix = self::prefix($r->kind);
            $byKind[$prefix] ??= array_fill_keys(self::STATUSES, 0);
            $byKind[$prefix][$r->status] += (int) $r->c;
            $total[$r->status] += (int) $r->c;
        }
        $labels = (array) $this->settings->get('notifications.kind_labels');
        $kinds = collect($byKind)->map(fn ($counts, $kind) => [
            'kind' => $kind, 'label' => $labels[$kind] ?? $kind, 'counts' => $counts,
            'total' => array_sum($counts), 'delivered_percent' => self::rate($counts),
        ])->sortByDesc('total')->values();

        // Qamrov: faol o'quvchi/o'qituvchi/adminlardan nechtasiga Telegram orqali yetib boriladi.
        $reach = [];
        foreach (self::ROLES as $role) {
            $all = DB::table('users')->where('active', true)->where('role', $role->value)->count();
            $unreachable = $this->unreachableQuery($role->value)->count();
            $reach[] = ['role' => $role->value, 'total' => $all, 'reachable' => $all - $unreachable,
                'percent' => $all ? round(($all - $unreachable) / $all * 100, 1) : null];
        }

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => ['counts' => $total, 'total' => array_sum($total), 'delivered_percent' => self::rate($total)],
            'by_kind' => $kinds,
            'reach' => $reach,
        ]);
    }

    /** Telegram orqali yetib bo'lmaydigan faol foydalanuvchilar (ulanmagan yoki botni bloklagan) — qo'ng'iroq qilish uchun. */
    public function unreachable(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => ['nullable', 'in:STUDENT,TEACHER,ADMIN,SUPER_ADMIN'], 'reason' => ['nullable', 'in:not_linked,blocked'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = $this->unreachableQuery($data['role'] ?? null, $data['reason'] ?? null)
            ->orderBy('users.first_name')->orderBy('users.last_name')
            ->paginate($this->settings->int('ui.page_size'));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($u) => $this->presentUser($u))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function unreachableCsv(Request $request): StreamedResponse
    {
        $data = $request->validate(['role' => ['nullable', 'in:STUDENT,TEACHER,ADMIN,SUPER_ADMIN'], 'reason' => ['nullable', 'in:not_linked,blocked']]);
        $rows = $this->unreachableQuery($data['role'] ?? null, $data['reason'] ?? null)->orderBy('users.first_name')->limit(20000)->get();
        $roles = (array) $this->settings->get('telegram.role_labels');
        $reasons = (array) $this->settings->get('notifications.delivery_labels');
        \App\Support\Audit::log('report.unreachable_csv', 'report', null, ['role' => $data['role'] ?? null, 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows, $roles, $reasons) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Ism', 'Telefon', 'Rol', 'Sabab', 'Guruhlar'], ';');
            foreach ($rows as $u) {
                $p = $this->presentUser($u);
                fputcsv($out, array_map(Csv::cell(...), [$p['name'], (string) $p['phone'], $roles[$p['role']] ?? $p['role'], $reasons[$p['reason']] ?? $p['reason'], implode(', ', $p['groups'])]), ';');
            }
            fclose($out);
        }, 'telegram_ulanmaganlar_'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function inbox(int $userId)
    {
        $prefixes = array_values(array_filter(array_map('strval', (array) $this->settings->get('notifications.inbox_kinds'))));

        return DB::table('notifications_log')->where('user_id', $userId)
            ->where(fn ($w) => $prefixes
                ? collect($prefixes)->each(fn ($p) => $w->orWhere('kind', 'like', $p.'%'))
                : $w->whereRaw('1 = 0'))
            // --skip bilan o'tkazilgan (yuborilmagan) eslatmalar ko'rsatilmaydi.
            ->where(fn ($w) => $w->whereNull('error')->orWhere('error', '!=', 'skipped'));
    }

    private function counts($user): array
    {
        $news = AnnouncementController::visibleTo($user)
            ->whereNotIn('id', DB::table('announcement_reads')->where('user_id', $user->id)->select('announcement_id'))->count();

        return ['unread' => $this->inbox($user->id)->whereNull('read_at')->count(), 'news_unread' => $news];
    }

    private function present(object $r): array
    {
        $payload = json_decode((string) $r->payload, true) ?: [];
        $prefix = self::prefix($r->kind);
        $labels = (array) $this->settings->get('notifications.kind_labels');

        return [
            'id' => (int) $r->id,
            'kind' => $r->kind,
            'type' => $prefix,
            'label' => $labels[$prefix] ?? $prefix,
            'text' => (string) ($payload['text'] ?? ''),
            'url' => self::url($r->kind),
            'delivery' => self::status($r->sent_at, $r->error),
            'read' => $r->read_at !== null,
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ];
    }

    private function unreachableQuery(?string $role = null, ?string $reason = null)
    {
        $blockedCol = Schema::hasColumn('telegram_links', 'blocked_at');
        $blockedIds = $this->blockedUserIds($blockedCol);
        $roles = $role ? [$role] : array_map(fn (Role $r) => $r->value, self::ROLES);
        $links = DB::table('telegram_links')->select('user_id');

        return DB::table('users')->where('users.active', true)->whereIn('users.role', $roles)
            ->where(function ($w) use ($links, $blockedIds, $reason) {
                if ($reason !== 'blocked') {
                    $w->orWhereNotIn('users.id', $links);
                }
                if ($reason !== 'not_linked') {
                    $w->orWhereIn('users.id', $blockedIds);
                }
            })
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.phone', 'users.role'])
            ->selectRaw('(select count(*) from telegram_links where telegram_links.user_id = users.id) as linked');
    }

    /** Botni bloklaganlar: Sprint 1 dagi telegram_links.blocked_at bo'lsa — shu, aks holda oxirgi xabar xatosi bo'yicha. */
    private function blockedUserIds(bool $blockedCol)
    {
        if ($blockedCol) {
            return DB::table('telegram_links')->whereNotNull('blocked_at')->select('user_id');
        }
        $latest = DB::table('notifications_log')->where('channel', 'telegram')->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('max(id)')->groupBy('user_id');

        return DB::table('notifications_log')->whereIn('id', $latest)
            ->where(fn ($w) => $w->where('error', 'blocked')->orWhere('error', 'like', '%blocked%')->orWhere('error', 'like', '%deactivated%')
                ->orWhere('error', 'like', '%chat not found%'))
            ->select('user_id');
    }

    private function presentUser(object $u): array
    {
        $groups = DB::table('group_members')->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->where('group_members.student_id', $u->id)->whereNull('group_members.left_at')->pluck('study_groups.name')
            ->merge(DB::table('study_groups')->where('teacher_id', $u->id)->where('active', true)->pluck('name'))->unique()->values()->all();

        return [
            'id' => (int) $u->id, 'name' => trim($u->first_name.' '.$u->last_name), 'phone' => $u->phone, 'role' => $u->role,
            'reason' => (int) $u->linked > 0 ? 'blocked' : 'not_linked', 'groups' => $groups,
        ];
    }

    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays($this->settings->int('notifications.report_days') - 1)->startOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Davr juda katta (366 kungacha).');

        return [$from, $to];
    }

    /** Yetkazish holati (SQL): Sprint 1 navbati "blocked" yozadi, eski yozuvlarda — Telegram 403 matni. */
    public static function statusSql(): string
    {
        return "case when sent_at is not null then 'sent'
            when error = 'telegram_not_linked' then 'not_linked'
            when error = 'skipped' then 'skipped'
            when error = 'bot_not_configured' then 'not_configured'
            when error = 'blocked' or error like '%blocked%' or error like '%deactivated%' or error like '%chat not found%' then 'blocked'
            else 'failed' end";
    }

    public static function status(?string $sentAt, ?string $error): string
    {
        return match (true) {
            $sentAt !== null => 'sent',
            $error === 'telegram_not_linked' => 'not_linked',
            $error === 'skipped' => 'skipped',
            $error === 'bot_not_configured' => 'not_configured',
            $error !== null && preg_match('/blocked|deactivated|chat not found/i', $error) === 1 => 'blocked',
            default => 'failed',
        };
    }

    /** "homework.new:12" → "homework", "announcement:3" → "announcement". */
    public static function prefix(string $kind): string
    {
        return preg_split('/[.:]/', $kind, 2)[0] ?: $kind;
    }

    /** Xabar turidan panel sahifasi. */
    public static function url(string $kind): ?string
    {
        $id = str_contains($kind, ':') ? (int) substr($kind, strrpos($kind, ':') + 1) : 0;

        return match (true) {
            str_starts_with($kind, 'homework.') && $id > 0 => "/dashboard/homework/$id",
            str_starts_with($kind, 'mark:') => '/dashboard/homework?tab=marks',
            str_starts_with($kind, 'announcement') => '/dashboard/news',
            str_starts_with($kind, 'lesson.') => '/dashboard/calendar',
            str_starts_with($kind, 'attendance.unmarked:') && $id > 0 => "/dashboard/sessions/$id",
            str_starts_with($kind, 'billing.') => '/dashboard/profile',
            str_starts_with($kind, 'certificate') => '/dashboard/certificates',
            str_starts_with($kind, 'attendance') => '/dashboard',
            default => null,
        };
    }

    private static function rate(array $counts): ?float
    {
        $attempted = array_sum($counts) - ($counts['skipped'] ?? 0);

        return $attempted > 0 ? round(($counts['sent'] ?? 0) / $attempted * 100, 1) : null;
    }
}
