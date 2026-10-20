<?php

namespace App\Telegram;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bot ichidagi admin: statistika, telefon bo'yicha qidirish, xabar tarqatish.
 * Faqat telegram.admin_chat_ids dagi chatlardan ishlaydi.
 */
class BotAdmin
{
    private const STATE_TTL = 900;

    public function __construct(private TelegramClient $tg, private Settings $settings, private Texts $t) {}

    /**
     * Admin: chat sozlamadagi ro'yxatda VA unga bog'langan akkaunt faol hamda user.manage huquqiga ega
     * (bloklangan yoki huquqi olingan admin bot orqali ham ishlay olmaydi).
     */
    public function isAdmin(int $chatId): bool
    {
        if (! in_array($chatId, array_map('intval', (array) $this->settings->get('telegram.admin_chat_ids')), true)) {
            return false;
        }
        $userId = \Illuminate\Support\Facades\DB::table('telegram_links')->where('chat_id', $chatId)->value('user_id');
        $user = $userId ? User::find($userId) : null;

        return $user !== null && $user->active && $user->hasPermission('user.manage');
    }

    /** Yangi ro'yxatdan o'tgan foydalanuvchi haqida barcha adminlarga xabar. */
    public function notifyNewUser(User $user, ?string $username): void
    {
        if (! $this->settings->bool('telegram.admin_notify_registrations')) {
            return;
        }
        $text = $this->t->get('telegram.text.admin_new_user', [
            'name' => trim($user->first_name.' '.$user->last_name),
            'phone' => $user->phone,
            'role' => $this->roleLabel($user->role->value),
            'username' => $username ? '@'.$username : '',
        ]);
        foreach ((array) $this->settings->get('telegram.admin_chat_ids') as $chatId) {
            try {
                $this->tg->sendMessage((int) $chatId, $text);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Admin buyrug'i: /admin, /stats, /find, /broadcast, /cancel. true — qayta ishlandi. */
    public function onCommand(int $chatId, string $command, string $arg): bool
    {
        if (! in_array($command, ['admin', 'stats', 'find', 'broadcast'], true)) {
            return false;
        }
        if (! $this->isAdmin($chatId)) {
            $this->say($chatId, 'telegram.text.admin_only');

            return true;
        }
        match ($command) {
            'admin' => $this->menu($chatId),
            'stats' => $this->tg->sendMessage($chatId, $this->stats()),
            'find' => $arg !== '' ? $this->search($chatId, $arg) : $this->askSearch($chatId),
            'broadcast' => $this->askAudience($chatId),
        };

        return true;
    }

    /** Kutilayotgan holat (qidiruv yoki tarqatish matni) bo'lsa, matnni qabul qiladi. */
    public function onText(int $chatId, string $text): bool
    {
        $state = Cache::get($this->stateKey($chatId));
        if (! $state || ! $this->isAdmin($chatId)) {
            return false;
        }
        if ($state === 'search') {
            $this->search($chatId, $text);

            return true;
        }
        if (str_starts_with($state, 'broadcast:')) {
            $this->preview($chatId, substr($state, 10), $text);

            return true;
        }

        return false;
    }

    /** Bekor qilinadigan narsa bo'lsa true. */
    public function cancel(int $chatId): bool
    {
        $state = Cache::pull($this->stateKey($chatId)) !== null;
        $draft = Cache::pull($this->draftKey($chatId)) !== null;
        $had = $state || $draft;
        if ($had) {
            $this->say($chatId, 'telegram.text.admin_cancelled');
        }

        return $had;
    }

    /** "adm:..." callback'lari. */
    public function onCallback(int $chatId, int $messageId, string $callbackId, string $data): void
    {
        if (! $this->isAdmin($chatId)) {
            $this->tg->answerCallback($callbackId, $this->t->get('telegram.text.admin_only'), true);

            return;
        }
        $parts = explode(':', $data);
        $this->tg->answerCallback($callbackId);
        match ($parts[1] ?? '') {
            'menu' => $this->menu($chatId, $messageId),
            'stats' => $this->tg->editMessageText($chatId, $messageId, $this->stats(), $this->backMarkup()),
            'find' => $this->askSearch($chatId),
            'bc' => $this->askAudience($chatId, $messageId),
            'to' => $this->askText($chatId, $messageId, $parts[2] ?? ''),
            'ok' => $this->confirm($chatId, $messageId, $parts[2] ?? ''),
            'no' => $this->drop($chatId, $messageId),
            default => null,
        };
    }

    // ── Bo'limlar ────────────────────────────────────────────

    private function menu(int $chatId, ?int $messageId = null): void
    {
        $markup = ['inline_keyboard' => [
            [['text' => $this->t->get('telegram.text.admin_btn_stats'), 'callback_data' => 'adm:stats']],
            [['text' => $this->t->get('telegram.text.admin_btn_search'), 'callback_data' => 'adm:find']],
            [['text' => $this->t->get('telegram.text.admin_btn_broadcast'), 'callback_data' => 'adm:bc']],
        ]];
        $text = $this->t->get('telegram.text.admin_menu');
        $messageId ? $this->tg->editMessageText($chatId, $messageId, $text, $markup) : $this->tg->sendMessage($chatId, $text, $markup);
    }

    public function stats(): string
    {
        $byRole = DB::table('users')->selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role');
        $roles = collect(Role::cases())
            ->map(fn (Role $r) => '   • '.$this->roleLabel($r->value).': '.(int) ($byRole[$r->value] ?? 0))
            ->implode("\n");
        $today = now()->toDateString();
        $sessionIds = DB::table('class_sessions')->whereDate('starts_at', $today)->where('cancelled', false)->pluck('id');
        $statuses = DB::table('attendances')->whereIn('session_id', $sessionIds)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $emoji = (array) $this->settings->get('telegram.attendance_emoji');
        $attendance = collect(['PRESENT', 'LATE', 'ABSENT', 'EXCUSED'])
            ->map(fn ($s) => ($emoji[$s] ?? $s).' '.(int) ($statuses[$s] ?? 0))->implode('  ');

        return $this->t->get('telegram.text.admin_stats', [
            'users' => DB::table('users')->count(),
            'active' => DB::table('users')->where('active', true)->count(),
            'roles' => $roles,
            'linked' => DB::table('telegram_links')->count(),
            'groups' => DB::table('study_groups')->where('active', true)->count(),
            'sessions' => $sessionIds->count(),
            'attendance' => $attendance,
            'date' => now()->format('d.m.Y'),
        ]);
    }

    private function askSearch(int $chatId): void
    {
        Cache::put($this->stateKey($chatId), 'search', self::STATE_TTL);
        $this->say($chatId, 'telegram.text.admin_search_prompt');
    }

    private function search(int $chatId, string $query): void
    {
        $digits = preg_replace('/\D+/', '', $query);
        if (strlen($digits) < 3) {
            Cache::put($this->stateKey($chatId), 'search', self::STATE_TTL);
            $this->say($chatId, 'telegram.text.admin_search_prompt');

            return;
        }
        Cache::forget($this->stateKey($chatId));
        $users = DB::table('users')
            ->leftJoin('telegram_links', 'telegram_links.user_id', '=', 'users.id')
            ->where('users.phone', 'like', '%'.$digits.'%')
            ->orderBy('users.id')->limit($this->settings->int('telegram.list_limit'))
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.phone', 'users.role', 'users.active',
                'telegram_links.username', 'telegram_links.telegram_id']);
        if ($users->isEmpty()) {
            $this->say($chatId, 'telegram.text.admin_search_empty');

            return;
        }
        $text = $users->map(fn ($u) => $this->t->get('telegram.text.admin_search_line', [
            'id' => $u->id,
            'name' => trim($u->first_name.' '.$u->last_name),
            'phone' => $u->phone,
            'role' => $this->roleLabel($u->role),
            'status' => $this->t->get($u->active ? 'telegram.text.admin_status_active' : 'telegram.text.admin_status_blocked'),
            'telegram' => $u->telegram_id
                ? ($u->username ? '@'.$u->username : (string) $u->telegram_id)
                : $this->t->get('telegram.text.admin_tg_none'),
        ]))->implode("\n\n");
        $this->tg->sendMessage($chatId, $text);
    }

    // ── Tarqatish: auditoriya → matn → ko'rib chiqish → tasdiqlash ──

    private function audiences(): array
    {
        return (array) $this->settings->get('telegram.text.admin_audience_labels');
    }

    private function recipients(string $audience): Builder
    {
        return DB::table('telegram_links')
            ->join('users', 'users.id', '=', 'telegram_links.user_id')
            ->where('users.active', true)
            ->when($audience !== 'all', fn ($q) => $q->where('users.role', $audience))
            ->select('telegram_links.user_id', 'telegram_links.chat_id');
    }

    private function askAudience(int $chatId, ?int $messageId = null): void
    {
        $rows = [];
        foreach ($this->audiences() as $key => $label) {
            if ($key === 'all' || Role::tryFrom($key)) {
                $rows[] = [['text' => $label.' ('.$this->recipients($key)->count().')', 'callback_data' => 'adm:to:'.$key]];
            }
        }
        $rows[] = [['text' => $this->t->get('telegram.text.btn_back'), 'callback_data' => 'adm:menu']];
        $text = $this->t->get('telegram.text.admin_audience');
        $markup = ['inline_keyboard' => $rows];
        $messageId ? $this->tg->editMessageText($chatId, $messageId, $text, $markup) : $this->tg->sendMessage($chatId, $text, $markup);
    }

    private function askText(int $chatId, int $messageId, string $audience): void
    {
        if (! isset($this->audiences()[$audience]) || ($audience !== 'all' && ! Role::tryFrom($audience))) {
            return;
        }
        Cache::put($this->stateKey($chatId), 'broadcast:'.$audience, self::STATE_TTL);
        $this->tg->editMessageText($chatId, $messageId, $this->t->get('telegram.text.admin_broadcast_prompt', [
            'audience' => $this->audiences()[$audience], 'count' => $this->recipients($audience)->count(),
        ]));
    }

    private function preview(int $chatId, string $audience, string $text): void
    {
        Cache::forget($this->stateKey($chatId));
        $count = $this->recipients($audience)->count();
        if ($count === 0) {
            $this->say($chatId, 'telegram.text.admin_broadcast_empty');

            return;
        }
        $token = Str::random(12);
        Cache::put($this->draftKey($chatId), ['token' => $token, 'audience' => $audience, 'text' => $text], self::STATE_TTL);
        $this->tg->sendMessage($chatId, $this->t->get('telegram.text.admin_broadcast_preview', [
            'audience' => $this->audiences()[$audience] ?? $audience, 'count' => $count, 'text' => mb_strlen($text) > 3500 ? mb_substr($text, 0, 3500).'…' : $text,
        ]), ['inline_keyboard' => [[
            ['text' => $this->t->get('telegram.text.admin_btn_confirm'), 'callback_data' => 'adm:ok:'.$token],
            ['text' => $this->t->get('telegram.text.admin_btn_cancel'), 'callback_data' => 'adm:no'],
        ]]]);
    }

    private function drop(int $chatId, int $messageId): void
    {
        Cache::forget($this->draftKey($chatId));
        Cache::forget($this->stateKey($chatId));
        $this->tg->editMessageText($chatId, $messageId, $this->t->get('telegram.text.admin_cancelled'));
    }

    private function confirm(int $chatId, int $messageId, string $token): void
    {
        $draft = Cache::get($this->draftKey($chatId));
        if (! $draft || ! hash_equals($draft['token'], $token)) {
            $this->tg->editMessageText($chatId, $messageId, $this->t->get('telegram.text.admin_broadcast_expired'));

            return;
        }
        // Ikki marta bosilsa (yoki Telegram qayta yuborsa) ikki marta yuborilmasin — atomik belgi.
        if (! Cache::add('tg.broadcast.sent.'.$draft['token'], 1, self::STATE_TTL)) {
            return;
        }
        Cache::forget($this->draftKey($chatId));
        $total = $this->recipients($draft['audience'])->count();
        $this->tg->editMessageText($chatId, $messageId, $this->t->get('telegram.text.admin_broadcast_started', ['count' => $total]));

        // Webhook'ga darhol javob qaytadi, yuborish javobdan keyin davom etadi.
        $once = false;
        app()->terminating(function () use (&$once, $chatId, $draft) {
            if (! $once) {
                $once = true;
                $this->broadcast($chatId, $draft['audience'], $draft['text']);
            }
        });
    }

    /** Yuborish: har bir xabar notifications_log ga yoziladi. */
    public function broadcast(int $adminChatId, string $audience, string $text): array
    {
        @set_time_limit(0);
        $id = (string) Str::uuid();
        $pause = (int) (1_000_000 / max(1, $this->settings->int('telegram.broadcast_per_second')));
        $sent = 0;
        $failed = 0;
        $this->recipients($audience)->orderBy('telegram_links.user_id')->chunk(200, function ($rows) use (&$sent, &$failed, $text, $id, $audience, $pause) {
            foreach ($rows as $r) {
                $error = null;
                try {
                    $this->tg->sendMessage((int) $r->chat_id, $text);
                    $sent++;
                } catch (Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 250);
                    $failed++;
                }
                DB::table('notifications_log')->insert([
                    'user_id' => $r->user_id, 'channel' => 'telegram', 'kind' => 'broadcast',
                    'payload' => json_encode(['text' => $text, 'broadcast_id' => $id, 'audience' => $audience], JSON_UNESCAPED_UNICODE),
                    'sent_at' => $error ? null : now(), 'error' => $error, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($pause > 0 && ! app()->runningUnitTests()) {
                    usleep($pause);
                }
            }
        });
        $result = ['sent' => $sent, 'failed' => $failed, 'total' => $sent + $failed];
        DB::table('audit_logs')->insert([
            'actor_id' => DB::table('telegram_links')->where('chat_id', $adminChatId)->value('user_id'),
            'action' => 'telegram.broadcast', 'entity' => 'broadcast', 'entity_id' => null,
            'data' => json_encode($result + ['id' => $id, 'audience' => $audience, 'admin_chat_id' => $adminChatId], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
        try {
            $this->tg->sendMessage($adminChatId, $this->t->get('telegram.text.admin_broadcast_done', $result));
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function backMarkup(): array
    {
        return ['inline_keyboard' => [[
            ['text' => $this->t->get('telegram.text.btn_refresh'), 'callback_data' => 'adm:stats'],
            ['text' => $this->t->get('telegram.text.btn_back'), 'callback_data' => 'adm:menu'],
        ]]];
    }

    private function roleLabel(string $role): string
    {
        return (string) (((array) $this->settings->get('telegram.role_labels'))[$role] ?? $role);
    }

    private function say(int $chatId, string $key, array $vars = []): void
    {
        $this->tg->sendMessage($chatId, $this->t->get($key, $vars));
    }

    private function stateKey(int $chatId): string
    {
        return "tg.admin.state.$chatId";
    }

    private function draftKey(int $chatId): string
    {
        return "tg.admin.draft.$chatId";
    }
}
