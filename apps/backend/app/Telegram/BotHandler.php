<?php

namespace App\Telegram;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Bot xabarlarini qayta ishlash. Barcha matnlar settings (telegram.text.*) dan. */
class BotHandler
{
    public function __construct(private TelegramClient $tg, private Settings $settings, private BotMenu $menu, private BotAdmin $admin) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $cq = $update['callback_query'];
            if (str_starts_with((string) ($cq['data'] ?? ''), 'lg:')) {
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
            $this->say($chatId, 'telegram.text.account_blocked', [], ['remove_keyboard' => true]);

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
        $phone = Phone::normalize($contact['phone_number']);
        $user = User::where('phone', $phone)->first();
        $remove = ['remove_keyboard' => true];

        if ($user && ! $user->active) {
            $this->say($chatId, 'telegram.text.account_blocked', [], $remove);

            return;
        }
        // Shu Telegram allaqachon shu akkauntga bog'langan — qayta bog'lamaymiz, "bog'landi" demaymiz.
        if ($user && (int) DB::table('telegram_links')->where('user_id', $user->id)->value('telegram_id') === (int) $from['id']) {
            DB::table('telegram_links')->where('user_id', $user->id)->update(['chat_id' => $chatId, 'username' => $from['username'] ?? null]);
            $this->menu->sendMenu($user, $chatId, 'telegram.text.welcome_back', ['name' => $user->first_name]);
            $this->confirmPendingLogin((int) $from['id'], $user, $chatId);

            return;
        }
        $isNew = false;
        if (! $user) {
            if (! $this->settings->bool('telegram.self_registration')) {
                $this->say($chatId, 'telegram.text.registration_closed', [], $remove);

                return;
            }
            $user = User::create([
                'phone' => $phone,
                'first_name' => $contact['first_name'] ?? $from['first_name'] ?? 'Foydalanuvchi',
                'last_name' => $contact['last_name'] ?? $from['last_name'] ?? null,
                'role' => Role::Student,
                'active' => true,
            ]);
            $isNew = true;
        }

        DB::transaction(function () use ($user, $from, $chatId) {
            DB::table('telegram_links')->where('telegram_id', $from['id'])->where('user_id', '!=', $user->id)->delete();
            DB::table('telegram_links')->updateOrInsert(
                ['user_id' => $user->id],
                ['telegram_id' => $from['id'], 'chat_id' => $chatId, 'username' => $from['username'] ?? null, 'linked_at' => now()],
            );
        });
        DB::table('audit_logs')->insert([
            'actor_id' => $user->id, 'action' => $isNew ? 'telegram.register' : 'telegram.link', 'entity' => 'user',
            'entity_id' => $user->id, 'data' => json_encode(['telegram_id' => $from['id']]), 'created_at' => now(),
        ]);

        $this->menu->sendMenu($user, $chatId, $isNew ? 'telegram.text.registered' : 'telegram.text.linked', ['name' => $user->first_name]);
        if ($isNew) {
            $this->admin->notifyNewUser($user, $from['username'] ?? null);
        }

        $this->confirmPendingLogin((int) $from['id'], $user, $chatId);
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
        $this->say($chatId, $textKey, [], [
            'keyboard' => [[['text' => $this->settings->get('telegram.text.share_phone_button'), 'request_contact' => true]]],
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
