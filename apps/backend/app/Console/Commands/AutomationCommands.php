<?php

namespace App\Console\Commands;

use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutomationCommands
{
    /**
     * Xabar shu foydalanuvchiga shu turda oldin yuborilganmi, navbatda turibdimi yoki doimiy sabab bilan
     * o'tkazilganmi (ulanmagan, bloklagan, --skip). Vaqtinchalik xato bilan yuborilmaganlari keyingi safar qayta uriniladi.
     */
    public static function alreadySent(int $userId, string $kind): bool
    {
        return DB::table('notifications_log')->where(['user_id' => $userId, 'kind' => $kind])
            ->where(fn ($w) => $w->whereNotNull('sent_at')->orWhereIn('error', Notifier::FINAL_ERRORS)->orWhere('error', 'like', 'bad_request%'))->exists()
            || DB::table('telegram_outbox')->where(['user_id' => $userId, 'kind' => $kind, 'status' => 'pending'])->exists();
    }

    /**
     * Rejaga ko'ra yetkazish.
     *  --dry-run: hech narsa yubormaydi va yozmaydi, faqat ro'yxatni chiqaradi.
     *  --skip:    yubormaydi, lekin "skipped" deb yozadi — bu xabarlar keyin ham yuborilmaydi
     *             (cron birinchi yoqilganda eski/joriy navbatni jim o'tkazish uchun).
     *
     * @param list<array{user_id:int, kind:string, key:string, vars:array}> $items
     */
    public static function deliver(Command $cmd, Notifier $notifier, array $items): int
    {
        $dry = (bool) $cmd->option('dry-run');
        $skip = (bool) $cmd->option('skip');
        $done = 0;
        foreach ($items as $it) {
            if (self::alreadySent($it['user_id'], $it['kind'])) {
                continue;
            }
            if ($dry) {
                $cmd->line("  [dry-run] user #{$it['user_id']} {$it['kind']} ".json_encode($it['vars'], JSON_UNESCAPED_UNICODE));
            } elseif ($skip) {
                DB::table('notifications_log')->insert([
                    'user_id' => $it['user_id'], 'channel' => 'telegram', 'kind' => $it['kind'],
                    'payload' => json_encode(['skipped' => true], JSON_UNESCAPED_UNICODE),
                    'sent_at' => null, 'error' => 'skipped', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $notifier->sendTemplate($it['user_id'], $it['kind'], $it['key'], $it['vars']);
            }
            $done++;
        }
        $cmd->info(($dry ? 'Yuboriladi (dry-run)' : ($skip ? "O'tkazildi (skip)" : 'Yuborildi')).": $done");

        return $done;
    }
}
