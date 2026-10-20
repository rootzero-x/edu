<?php

namespace App\Telegram;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Bot xabarlarini qayta ishlash. Barcha matnlar settings (telegram.text.*) dan. */
class BotHandler
{
    public function __construct(private TelegramClient $tg, private Settings $settings, private BotMenu $menu, private BotAdmin $admin, private BotLeads $leads) {}

    /**
     * Har qanday kutilmagan xato: log + foydalanuvchiga do'stona umumiy matn (xom API xatosi ko'rsatilmaydi),
     * callback esa har doim javob oladi (tugmadagi "soat" osilib qolmasin).
     */
    public function handle(array $update): void
    {
        $fromId = (int) ($update['callback_query']['from']['id'] ?? $update['message']['from']['id'] ?? 0);
        if ($fromId) {
            // Botga qayta yozgan foydalanuvchi endi "bloklagan" emas.
            Outbox::unblock($fromId);
        }
        try {
            $this->dispatch($update);
        } catch (Throwable $e) {
            report($e);
            $this->apologize($update);
        }
    }

    private function apologize(array $update): void
    {
        try {
            $text = $this->settings->get('telegram.text.error_generic');
            if (isset($update['callback_query']['id'])) {
                try {
                    $this->tg->answerCallback((string) $update['callback_query']['id'], $text, true);

                    return;
                } catch (Throwable) {
                    // allaqachon javob berilgan — xabar sifatida yuboramiz
                }
            }
            $chatId = (int) ($update['callback_query']['message']['chat']['id'] ?? $update['message']['chat']['id'] ?? 0);
            if ($chatId && ($update['message']['chat']['type'] ?? 'private') === 'private') {
                $this->tg->sendMessage($chatId, $text);
            }
        } catch (Throwable) {
            // Telegram ishlamasa — jim.
        }
    }

    private function dispatch(array $update): void
    {
        if (isset($update['callback_query'])) {
            $cq = $update['callback_query'];
            $data = (string) ($cq['data'] ?? '');
            if (str_starts_with($data, 'ld:')) {
                $this->leads->onCallback($cq);

                return;
            }
            if (str_starts_with($data, 'lg:')) {
                $this->onLoginCallback($cq);

                return;
            }
            $this->menu->onCallback($cq);

            return;
        }
        $msg = $update['message'] ?? null;
        if (! $msg || ($msg['chat']['type'] ?? '') !== 'private') {
            return;
        }
        $from = $msg['from'];
        $chatId = (int) $msg['chat']['id'];

        if (isset($msg['contact'])) {
            $this->onContact($from, $chatId, $msg['contact']);

            return;
        }
        $text = trim($msg['text'] ?? '');
        if (preg_match('/^\/start(?:@\S+)?(?:\s+(.*))?$/s', $text, $m)) {
            $this->onStart($from, $chatId, trim($m[1] ?? ''));

            return;
        }
        if ($text !== '' && $this->leads->isTrigger($text)) {
            $this->leads->start($chatId);

            return;
        }
        if ($text !== '' && ! $this->menu->onText($chatId, $from, $text)) {
            $this->askPhone($chatId, 'telegram.text.need_phone');
        }
    }

    private function onStart(array $from, int $chatId, string $payload): void
    {
        $user = $this->linkedUser((int) $from['id']);

        if (str_starts_with($payload, 'login_')) {
            $code = substr($payload, 6);
            if (! $this->pendingRequest($code)) {
                $this->say($chatId, 'telegram.text.login_expired');

                return;
            }
            if ($user) {
                $this->askLoginConfirm($code, $user, $chatId);

                return;
            }
            Cache::put($this->pendingKey((int) $from['id']), $code, $this->settings->int('telegram.login_code_ttl_seconds'));
        }

        if ($user && $user->active) {
            $this->menu->sendMenu($user, $chatId, 'telegram.text.welcome_back', ['name' => $user->first_name]);

            return;
        }
        if ($user) {
            $this->say($chatId, $user->isPending() ? 'telegram.text.account_pending' : 'telegram.text.account_blocked', [], ['remove_keyboard' => true]);

            return;
        }
        $this->askPhone($chatId);
    }

    private function onContact(array $from, int $chatId, array $contact): void
    {
        if ((int) ($contact['user_id'] ?? 0) !== (int) $from['id']) {
            $this->askPhone($chatId, 'telegram.text.not_own_contact');

            return;
        }
        // Ariza jarayonida yuborilgan raqam — akkaunt yaratilmaydi, ariza yoziladi.
        if ($this->leads->onContact($from, $chatId, $contact)) {
            return;
        }
        // Yagona normalizatsiya (sayt va admin bilan bir xil). Eski, normalizatsiyadan oldingi yozuvlar ham topilsin.
        $phone = \App\Support\Phone::normalize($contact['phone_number']);
        $legacy = '+'.preg_replace('/\D+/', '', (string) $contact['phone_number']);
        $user = User::whereIn('phone', array_unique(array_filter([$phone, $legacy])))->orderByRaw('phone = ? desc', [$phone ?? $legacy])->first();
        $remove = ['remove_keyboard' => true];

        if ($user && ! $user->active) {
            // Ariza egasi Telegram'ni boshqa akkauntdan qayta ulasa ham bog'lab qo'yamiz — tasdiq xabari yetib borsin.
            if ($user->isPending()) {
                $this->link($user, $from, $chatId);
            }
            $this->say($chatId, $user->isPending() ? 'telegram.text.account_pending' : 'telegram.text.account_blocked', [], $remove);

            return;
        }
        // Shu Telegram allaqachon shu akkauntga bog'langan — qayta bog'lamaymiz, "bog'landi" demaymiz.
        if ($user && (int) DB::table('telegram_links')->where('user_id', $user->id)->value('telegram_id') === (int) $from['id']) {
            DB::table('telegram_links')->where('user_id', $user->id)->update(['chat_id' => $chatId, 'username' => $from['username'] ?? null]);
            $this->menu->sendMenu($user, $chatId, 'telegram.text.welcome_back', ['name' => $user->first_name]);
            $this->confirmPendingLogin((int) $from['id'], $user, $chatId);

            return;
        }
        if (! $user) {
            if (! $this->settings->bool('telegram.self_registration')) {
                $this->say($chatId, 'telegram.text.registration_closed', [], $remove);

                return;
            }
            if ($phone === null) {
                $this->say($chatId, 'telegram.text.phone_not_supported', [], $remove);

                return;
            }
            $applicant = [
                'phone' => $phone,
                'first_name' => $contact['first_name'] ?? $from['first_name'] ?? 'Foydalanuvchi',
                'last_name' => $contact['last_name'] ?? $from['last_name'] ?? null,
            ];
            $this->register($applicant, Role::Student, $from, $chatId);

            return;
        }

        $this->link($user, $from, $chatId);
        DB::table('audit_logs')->insert([
            'actor_id' => $user->id, 'action' => 'telegram.link', 'entity' => 'user',
            'entity_id' => $user->id, 'data' => json_encode(['telegram_id' => $from['id']]), 'created_at' => now(),
        ]);
        $this->menu->sendMenu($user, $chatId, 'telegram.text.linked', ['name' => $user->first_name]);
        $this->confirmPendingLogin((int) $from['id'], $user, $chatId);
    }

    /** Yangi akkaunt: ariza rejimida faol emas (pending_at), aks holda darhol faol. */
    private function register(array $applicant, Role $role, array $from, int $chatId): void
    {
        $pending = $this->settings->bool('telegram.self_registration_approval');
        $user = User::create($applicant + ['role' => $role, 'active' => ! $pending]);
        if ($pending) {
            $user->forceFill(['pending_at' => now()])->save();
        }
        $this->link($user, $from, $chatId);
        DB::table('audit_logs')->insert([
            'actor_id' => $user->id, 'action' => 'telegram.register', 'entity' => 'user',
            'entity_id' => $user->id, 'data' => json_encode(['telegram_id' => $from['id'], 'pending' => $pending, 'role' => $role->value]), 'created_at' => now(),
        ]);
        if ($pending) {
            $this->say($chatId, 'telegram.text.registration_pending', ['name' => $user->first_name], ['remove_keyboard' => true]);
            $this->admin->notifyNewUser($user, $from['username'] ?? null);

            return;
        }
        $this->menu->sendMenu($user, $chatId, 'telegram.text.registered', ['name' => $user->first_name]);
        $this->admin->notifyNewUser($user, $from['username'] ?? null);
        $this->confirmPendingLogin((int) $from['id'], $user, $chatId);
    }

    private function link(User $user, array $from, int $chatId): void
    {
        DB::transaction(function () use ($user, $from, $chatId) {
            DB::table('telegram_links')->where('telegram_id', $from['id'])->where('user_id', '!=', $user->id)->delete();
            DB::table('telegram_links')->updateOrInsert(
                ['user_id' => $user->id],
                ['telegram_id' => $from['id'], 'chat_id' => $chatId, 'username' => $from['username'] ?? null, 'linked_at' => now(),
                    'blocked_at' => null, 'blocked_reason' => null],
            );
        });
    }

    /** Telefon so'ralishidan oldin saytdan kirish havolasi bosilgan bo'lsa — endi tasdiq so'raymiz. */
    private function confirmPendingLogin(int $telegramId, User $user, int $chatId): void
    {
        $code = Cache::pull($this->pendingKey($telegramId));
        if ($code && $this->pendingRequest($code)) {
            $this->askLoginConfirm($code, $user, $chatId);
        }
    }

    /**
     * Saytga kirishni tasdiqlash so'rovi. Havolani bosishning o'zi yetarli emas: begona odam yuborgan
     * havola bilan akkauntni egallab olishning oldini olish uchun foydalanuvchi IP/vaqtni ko'rib tasdiqlaydi.
     */
    private function askLoginConfirm(string $code, User $user, int $chatId): void
    {
        if (! $user->active) {
            $this->say($chatId, 'telegram.text.account_blocked');

            return;
        }
        $req = DB::table('telegram_login_requests')->where('code', $code)->first(['ip', 'created_at']);
        $this->say($chatId, 'telegram.text.login_confirm', [
            'name' => $user->first_name,
            'ip' => $req->ip ?? '—',
            'time' => $req ? \Illuminate\Support\Carbon::parse($req->created_at)->format('d.m.Y H:i') : '',
        ], ['inline_keyboard' => [[
            ['text' => $this->settings->get('telegram.text.btn_login_yes'), 'callback_data' => 'lg:y:'.$code],
            ['text' => $this->settings->get('telegram.text.btn_login_no'), 'callback_data' => 'lg:n:'.$code],
        ]]]);
    }

    private function onLoginCallback(array $cq): void
    {
        [, $answer, $code] = array_pad(explode(':', (string) $cq['data'], 3), 3, '');
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);
        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        $user = $this->linkedUser((int) $cq['from']['id']);
        $render = function (string $key) use ($chatId, $messageId) {
            $vars = ['site' => $this->settings->get('site.name')];
            $text = strtr((string) $this->settings->get($key), array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($vars)), array_values($vars)));
            $messageId ? $this->tg->editMessageText($chatId, $messageId, $text) : $this->tg->sendMessage($chatId, $text);
        };
        $this->tg->answerCallback((string) $cq['id']);
        if (! $user || ! $user->active || ! $this->pendingRequest($code)) {
            $render(! $user || $user->active ? 'telegram.text.login_expired' : 'telegram.text.account_blocked');

            return;
        }
        if ($answer !== 'y') {
            DB::table('telegram_login_requests')->where('code', $code)->where('status', 'PENDING')
                ->update(['expires_at' => now()->subSecond(), 'updated_at' => now()]);
            $render('telegram.text.login_rejected');

            return;
        }
        $updated = DB::table('telegram_login_requests')
            ->where('code', $code)->where('status', 'PENDING')->where('expires_at', '>', now())
            ->update(['status' => 'CONFIRMED', 'user_id' => $user->id, 'updated_at' => now()]);
        $render($updated ? 'telegram.text.login_confirmed' : 'telegram.text.login_expired');
    }

    private function pendingRequest(string $code): bool
    {
        return $code !== '' && DB::table('telegram_login_requests')
            ->where('code', $code)->where('status', 'PENDING')->where('expires_at', '>', now())->exists();
    }

    private function linkedUser(int $telegramId): ?User
    {
        $userId = DB::table('telegram_links')->where('telegram_id', $telegramId)->value('user_id');

        return $userId ? User::find($userId) : null;
    }

    private function pendingKey(int $telegramId): string
    {
        return "tg.pending_login.$telegramId";
    }

    private function askPhone(int $chatId, string $textKey = 'telegram.text.welcome'): void
    {
        $rows = [[['text' => $this->settings->get('telegram.text.share_phone_button'), 'request_contact' => true]]];
        if ($this->leads->enabled()) {
            $rows[] = [['text' => $this->settings->get('telegram.text.btn_lead')]];
        }
        $this->say($chatId, $textKey, [], [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private function say(int $chatId, string $key, array $vars = [], ?array $markup = null): void
    {
        $vars += ['site' => $this->settings->get('site.name')];
        $text = strtr($this->settings->get($key), array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($vars)), array_values($vars)));
        $this->tg->sendMessage($chatId, $text, $markup);
    }
}
