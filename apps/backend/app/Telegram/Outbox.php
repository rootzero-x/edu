<?php

namespace App\Telegram;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telegram xabarlari navbati (telegram_outbox). Shared hostingda doimiy worker yo'q, shuning uchun:
 *  - har daqiqada cron `tisu:outbox-drain` ni ishga tushiradi (≈40 soniya ishlaydi, navbat bo'sh bo'lsa darhol tugaydi);
 *  - so'rovdan keyin (defer) oz sonli xabar darhol yuboriladi (telegram.outbox_inline_limit) — ota-onaga xabar kechikmasin.
 * Tezlik: telegram.send_per_second (umumiy, bitta qulf orqali). 429 — retry_after kutiladi, 403 — chat "bloklagan" deb belgilanadi.
 */
class Outbox
{
    private const LOCK = 'tg.outbox.lock';

    private const PAUSE = 'tg.outbox.pause_until';

    private bool $kicked = false;

    public function __construct(private TelegramClient $tg, private Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('telegram.outbox_enabled');
    }

    public function enqueue(?int $userId, int $chatId, string $kind, string $text, ?array $markup = null, ?string $batchId = null): int
    {
        return (int) DB::table('telegram_outbox')->insertGetId([
            'user_id' => $userId, 'chat_id' => $chatId, 'kind' => mb_substr($kind, 0, 64), 'text' => $text,
            'reply_markup' => $markup ? json_encode($markup, JSON_UNESCAPED_UNICODE) : null, 'batch_id' => $batchId,
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Shu so'rov tugagach bir oz xabarni darhol yuborish (qolgani — cron). */
    public function kick(): void
    {
        $limit = $this->settings->int('telegram.outbox_inline_limit');
        if ($this->kicked || $limit <= 0) {
            return;
        }
        $this->kicked = true;
        defer(function () use ($limit) {
            $this->kicked = false;
            try {
                $this->drain($limit, 8.0);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Navbatni bo'shatish. @return array{sent:int, failed:int, blocked:int, retried:int, left:int}
     * Bir vaqtda faqat bitta jarayon yuboradi (umumiy tezlik chegarasi shu bilan ta'minlanadi).
     */
    public function drain(int $maxMessages = PHP_INT_MAX, float $maxSeconds = 40.0): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'blocked' => 0, 'retried' => 0, 'left' => 0];
        $lock = Cache::lock(self::LOCK, (int) ceil($maxSeconds) + 30);
        if (! $lock->get()) {
            return $stats + ['locked' => true];
        }
        try {
            $deadline = microtime(true) + $maxSeconds;
            $gap = 1.0 / max(1, $this->settings->int('telegram.send_per_second'));
            $maxAttempts = $this->settings->int('telegram.outbox_max_attempts');
            $done = 0;
            $next = microtime(true);
            while ($done < $maxMessages && microtime(true) < $deadline) {
                if (($pause = (int) Cache::get(self::PAUSE, 0)) > now()->getTimestamp()) {
                    // 429: kutish vaqti byudjetga sig'sa kutamiz, aks holda keyingi cron'ga qoldiramiz.
                    if ($pause >= $deadline || app()->runningUnitTests()) {
                        break;
                    }
                    usleep((int) (($pause - now()->getTimestamp()) * 1_000_000));
                }
                $rows = DB::table('telegram_outbox')->where('status', 'pending')->where('available_at', '<=', now())
                    ->orderBy('id')->limit(min(100, $maxMessages - $done))->get();
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $row) {
                    if ($done >= $maxMessages || microtime(true) >= $deadline || (int) Cache::get(self::PAUSE, 0) > now()->getTimestamp()) {
                        break;
                    }
                    if (($wait = $next - microtime(true)) > 0 && ! app()->runningUnitTests()) {
                        usleep((int) ($wait * 1_000_000));
                    }
                    $next = microtime(true) + $gap;
                    $stats[$this->deliver($row, $maxAttempts)]++;
                    $done++;
                }
            }
            $this->finishBatches();
            $stats['left'] = DB::table('telegram_outbox')->where('status', 'pending')->count();
        } finally {
            $lock->release();
        }

        return $stats;
    }

    /** Bitta xabarni yuborish. @return 'sent'|'failed'|'blocked'|'retried' */
    private function deliver(object $row, int $maxAttempts): string
    {
        // Navbatga qo'yilgandan keyin bloklagan bo'lsa — yubormaymiz.
        if ($row->user_id && DB::table('telegram_links')->where('user_id', $row->user_id)->whereNotNull('blocked_at')->exists()) {
            $this->finish($row, 'blocked', 'blocked');

            return 'blocked';
        }
        try {
            $this->tg->sendMessage((int) $row->chat_id, (string) $row->text, $row->reply_markup ? json_decode($row->reply_markup, true) : null);
            $this->finish($row, 'sent');

            return 'sent';
        } catch (TelegramApiException $e) {
            if ($e->rateLimited()) {
                $after = max(1, (int) ($e->retryAfter ?? 5));
                Cache::put(self::PAUSE, now()->getTimestamp() + $after, $after + 5);
                DB::table('telegram_outbox')->where('id', $row->id)->update(['available_at' => now()->addSeconds($after), 'last_error' => '429', 'updated_at' => now()]);

                return 'retried';
            }
            if ($e->unreachable()) {
                self::markBlocked((int) $row->chat_id, $e->getMessage());
                $this->finish($row, 'blocked', 'blocked');

                return 'blocked';
            }
            if ($e->errorCode === 400) {
                // Noto'g'ri so'rov (masalan, matn juda uzun) — qayta urinish foydasiz.
                $this->finish($row, 'failed', mb_substr($e->getMessage(), 0, 250));

                return 'failed';
            }

            return $this->retryOrFail($row, $maxAttempts, $e->getMessage());
        } catch (Throwable $e) {
            return $this->retryOrFail($row, $maxAttempts, $e->getMessage());
        }
    }

    private function retryOrFail(object $row, int $maxAttempts, string $error): string
    {
        $attempts = (int) $row->attempts + 1;
        if ($attempts >= $maxAttempts) {
            $this->finish($row, 'failed', mb_substr($error, 0, 250), $attempts);

            return 'failed';
        }
        // 30s, 60s, 2m, 4m, ... (eng ko'pi 1 soat)
        DB::table('telegram_outbox')->where('id', $row->id)->update([
            'attempts' => $attempts, 'available_at' => now()->addSeconds(min(3600, 30 * 2 ** ($attempts - 1))),
            'last_error' => mb_substr($error, 0, 250), 'updated_at' => now(),
        ]);

        return 'retried';
    }

    private function finish(object $row, string $status, ?string $error = null, ?int $attempts = null): void
    {
        DB::table('telegram_outbox')->where('id', $row->id)->update([
            'status' => $status, 'last_error' => $error, 'sent_at' => $status === 'sent' ? now() : null,
            'attempts' => $attempts ?? ((int) $row->attempts + 1), 'updated_at' => now(),
        ]);
        if ($row->user_id) {
            DB::table('notifications_log')->insert([
                'user_id' => $row->user_id, 'channel' => 'telegram', 'kind' => $row->kind,
                'payload' => json_encode(array_filter(['text' => $row->text, 'outbox_id' => $row->id, 'batch_id' => $row->batch_id]), JSON_UNESCAPED_UNICODE),
                'sent_at' => $status === 'sent' ? now() : null, 'error' => $error, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Chat botni bloklagan (403) yoki mavjud emas: keyingi xabarlar o'tkazib yuboriladi, adminlar ro'yxatda ko'radi. */
    public static function markBlocked(int $chatId, string $reason): void
    {
        DB::table('telegram_links')->where('chat_id', $chatId)->whereNull('blocked_at')
            ->update(['blocked_at' => now(), 'blocked_reason' => mb_substr($reason, 0, 250)]);
    }

    /** Foydalanuvchi botga qayta yozdi — blok olib tashlanadi. */
    public static function unblock(int $telegramId): void
    {
        DB::table('telegram_links')->where('telegram_id', $telegramId)->whereNotNull('blocked_at')
            ->update(['blocked_at' => null, 'blocked_reason' => null]);
    }

    // ── Tarqatish (broadcast) paketlari ──────────────────────

    /** @param iterable<object{user_id:int, chat_id:int}> $recipients */
    public function createBatch(string $kind, iterable $recipients, string $text, ?int $adminChatId, ?int $actorId, array $meta = []): array
    {
        $id = (string) Str::uuid();
        $total = 0;
        DB::transaction(function () use ($id, $kind, $recipients, $text, $adminChatId, $actorId, $meta, &$total) {
            DB::table('telegram_batches')->insert([
                'id' => $id, 'kind' => $kind, 'admin_chat_id' => $adminChatId, 'actor_id' => $actorId,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'total' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $buffer = [];
            foreach ($recipients as $r) {
                $buffer[] = [
                    'user_id' => $r->user_id, 'chat_id' => $r->chat_id, 'kind' => $kind, 'text' => $text, 'batch_id' => $id,
                    'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ];
                $total++;
                if (count($buffer) >= 500) {
                    DB::table('telegram_outbox')->insert($buffer);
                    $buffer = [];
                }
            }
            if ($buffer) {
                DB::table('telegram_outbox')->insert($buffer);
            }
            DB::table('telegram_batches')->where('id', $id)->update(['total' => $total]);
        });

        return ['id' => $id, 'total' => $total];
    }

    /** Tugagan paketlar: natija adminga yuboriladi va auditga yoziladi (bir marta). */
    public function finishBatches(): void
    {
        $open = DB::table('telegram_batches')->whereNull('finished_at')->get();
        foreach ($open as $b) {
            $counts = DB::table('telegram_outbox')->where('batch_id', $b->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            if ((int) ($counts['pending'] ?? 0) > 0) {
                continue;
            }
            if (! DB::table('telegram_batches')->where('id', $b->id)->whereNull('finished_at')->update(['finished_at' => now(), 'updated_at' => now()])) {
                continue;
            }
            $result = ['sent' => (int) ($counts['sent'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0) + (int) ($counts['blocked'] ?? 0),
                'blocked' => (int) ($counts['blocked'] ?? 0), 'total' => (int) $b->total];
            DB::table('audit_logs')->insert([
                'actor_id' => $b->actor_id, 'action' => 'telegram.broadcast', 'entity' => 'broadcast', 'entity_id' => null,
                'data' => json_encode($result + ['id' => $b->id, 'admin_chat_id' => $b->admin_chat_id] + (array) json_decode((string) $b->meta, true), JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
            if ($b->admin_chat_id) {
                try {
                    $this->tg->sendMessage((int) $b->admin_chat_id, app(Texts::class)->get('telegram.text.admin_broadcast_done', $result));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** Admin holat kartasi uchun. */
    public static function stats(): array
    {
        $since = now()->subDay();

        return [
            'pending' => DB::table('telegram_outbox')->where('status', 'pending')->count(),
            'sent_24h' => DB::table('telegram_outbox')->where('status', 'sent')->where('updated_at', '>=', $since)->count(),
            'failed_24h' => DB::table('telegram_outbox')->where('status', 'failed')->where('updated_at', '>=', $since)->count(),
            'blocked_chats' => DB::table('telegram_links')->whereNotNull('blocked_at')->count(),
            'linked' => DB::table('telegram_links')->whereNull('blocked_at')->count(),
            'oldest_pending_at' => DB::table('telegram_outbox')->where('status', 'pending')->min('created_at'),
        ];
    }
}
