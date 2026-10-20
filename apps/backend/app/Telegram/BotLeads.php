<?php

namespace App\Telegram;

use App\Models\Course;
use App\Support\Leads;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bot orqali ariza: «📝 Ariza qoldirish» (yoki /ariza) → kurs tanlash → telefon (bog'lanmaganlar uchun) → ariza.
 * Akkaunt yaratilmaydi: ariza administratorga tushadi, u bog'lanib o'quvchiga aylantiradi.
 */
class BotLeads
{
    private const TTL = 1800;

    public function __construct(private TelegramClient $tg, private Settings $settings, private Texts $t, private Leads $leads) {}

    /** Matn ariza boshlash buyrug'imi (/ariza yoki tugma). */
    public function isTrigger(string $text): bool
    {
        return preg_match('/^\/(ariza|lead)(@\S+)?$/i', $text) === 1 || $text === $this->t->get('telegram.text.btn_lead');
    }

    public function enabled(): bool
    {
        return $this->settings->bool('leads.bot_enabled');
    }

    public function start(int $chatId): void
    {
        if (! $this->enabled()) {
            $this->tg->sendMessage($chatId, $this->t->get('telegram.text.lead_disabled', ['phone' => (string) $this->settings->get('site.contact_phone')]));

            return;
        }
        $rows = Course::where('published', true)->orderBy('title')->limit(30)->get(['id', 'title'])
            ->map(fn ($c) => [['text' => mb_substr($c->title, 0, 60), 'callback_data' => 'ld:c:'.$c->id]])->all();
        $rows[] = [['text' => $this->t->get('telegram.text.lead_course_any'), 'callback_data' => 'ld:c:0']];
        $this->tg->sendMessage($chatId, $this->t->get('telegram.text.lead_pick_course'), ['inline_keyboard' => $rows]);
    }

    public function onCallback(array $cq): void
    {
        $this->tg->answerCallback((string) $cq['id']);
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);
        $telegramId = (int) $cq['from']['id'];
        if (! $chatId || ! $this->enabled() || ! preg_match('/^ld:c:(\d+)$/', (string) $cq['data'], $m)) {
            return;
        }
        $courseId = (int) $m[1] ?: null;
        if ($courseId && ! Course::whereKey($courseId)->where('published', true)->exists()) {
            $courseId = null;
        }
        // Bog'langan foydalanuvchi — telefon allaqachon ma'lum.
        $userId = DB::table('telegram_links')->where('telegram_id', $telegramId)->value('user_id');
        $user = $userId ? \App\Models\User::find($userId) : null;
        if ($user && $user->active) {
            $this->create($chatId, $telegramId, trim($user->first_name.' '.$user->last_name), $user->phone, $courseId, null);

            return;
        }
        Cache::put($this->key($telegramId), ['course_id' => $courseId], self::TTL);
        $this->tg->sendMessage($chatId, $this->t->get('telegram.text.lead_ask_phone'), [
            'keyboard' => [[['text' => $this->t->get('telegram.text.share_phone_button'), 'request_contact' => true]]],
            'resize_keyboard' => true, 'one_time_keyboard' => true,
        ]);
    }

    /** Ariza jarayonidagi foydalanuvchi telefon yubordi. true — qayta ishlandi (ro'yxatdan o'tkazish kerak emas). */
    public function onContact(array $from, int $chatId, array $contact): bool
    {
        $state = Cache::pull($this->key((int) $from['id']));
        if (! is_array($state)) {
            return false;
        }
        $phone = Phone::normalize((string) ($contact['phone_number'] ?? ''));
        if (! $phone) {
            return false;
        }
        $name = trim(($contact['first_name'] ?? $from['first_name'] ?? '').' '.($contact['last_name'] ?? $from['last_name'] ?? '')) ?: 'Telegram';
        $this->create($chatId, (int) $from['id'], $name, $phone, $state['course_id'] ?? null, ['remove_keyboard' => true], $from['username'] ?? null);

        return true;
    }

    private function create(int $chatId, int $telegramId, string $name, string $phone, ?int $courseId, ?array $markup, ?string $username = null): void
    {
        $r = $this->leads->capture(['name' => mb_substr($name, 0, 128), 'phone' => $phone, 'course_id' => $courseId, 'telegram_id' => $telegramId, 'consent' => true], 'bot',
            array_filter(['username' => $username]));
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $r['created'] ? 'lead.bot_create' : 'lead.bot_duplicate', 'entity' => 'lead', 'entity_id' => $r['lead']->id,
            'data' => json_encode(['telegram_id' => $telegramId]), 'created_at' => now(),
        ]);
        $course = $courseId ? (string) Course::whereKey($courseId)->value('title') : '';
        $this->tg->sendMessage($chatId, $this->t->get('telegram.text.lead_done', ['name' => explode(' ', $name)[0], 'course' => $course]), $markup);
        if ($r['created']) {
            $this->leads->notifyAdmins($r['lead']);
        }
    }

    private function key(int $telegramId): string
    {
        return "tg.lead.$telegramId";
    }
}
