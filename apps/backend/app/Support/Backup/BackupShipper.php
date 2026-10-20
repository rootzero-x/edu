<?php

namespace App\Support\Backup;

use App\Support\Settings;
use App\Telegram\BotAdmin;
use App\Telegram\TelegramClient;
use App\Telegram\Texts;
use RuntimeException;
use Throwable;

/**
 * Zaxirani serverdan tashqariga chiqarish: admin Telegram chat(lar)iga hujjat sifatida (Bot API chegarasi — 50 MB).
 * Katta fayl backup.telegram_part_mb bo'yicha bo'laklarga bo'linadi (.part1of3, ...); tiklashda ketma-ket birlashtiriladi.
 */
final class BackupShipper
{
    public function __construct(private TelegramClient $tg, private Settings $settings, private BotAdmin $admin, private Texts $t) {}

    /** @return array{ok: bool, chats: int, parts: int, sha256: string, error: ?string} */
    public function toTelegram(string $path): array
    {
        $size = (int) filesize($path);
        $sha = (string) hash_file('sha256', $path);
        $maxMb = $this->settings->int('backup.offsite_max_mb');
        if ($size > $maxMb * 1048576) {
            return ['ok' => false, 'chats' => 0, 'parts' => 0, 'sha256' => $sha, 'error' => "Fayl juda katta: ".self::human($size)." > $maxMb MB (backup.offsite_max_mb)."];
        }
        $chats = array_values(array_filter(array_map('intval', (array) $this->settings->get('telegram.admin_chat_ids')), fn ($c) => $this->admin->isAdmin($c)));
        if (! $chats) {
            return ['ok' => false, 'chats' => 0, 'parts' => 0, 'sha256' => $sha, 'error' => "telegram.admin_chat_ids da faol admin chati yo'q."];
        }
        $partBytes = $this->settings->int('backup.telegram_part_mb') * 1048576;
        $parts = max(1, (int) ceil($size / $partBytes));
        $name = basename($path);
        $sent = 0;
        $errors = [];
        foreach ($chats as $chat) {
            try {
                for ($i = 0; $i < $parts; $i++) {
                    $label = $parts > 1 ? ($i + 1).'/'.$parts : '1/1';
                    $file = $parts > 1 ? $name.'.part'.($i + 1).'of'.$parts : $name;
                    $caption = $this->t->get('telegram.text.backup_caption', [
                        'file' => $file, 'size' => self::human($size), 'part' => $label, 'sha' => $sha,
                    ]);
                    $this->sendRange($chat, $path, $i * $partBytes, min($partBytes, $size - $i * $partBytes), $file, $caption);
                }
                $sent++;
            } catch (Throwable $e) {
                $errors[] = "chat $chat: ".mb_substr($e->getMessage(), 0, 200);
            }
        }

        return ['ok' => $sent > 0 && ! $errors, 'chats' => $sent, 'parts' => $parts, 'sha256' => $sha, 'error' => $errors ? implode('; ', $errors) : null];
    }

    /** Faylning bir qismini vaqtinchalik faylga ko'chirib (xotiraga to'liq yuklamasdan) yuboradi. */
    private function sendRange(int $chat, string $path, int $offset, int $length, string $filename, string $caption): void
    {
        $src = fopen($path, 'rb');
        $tmp = tempnam(dirname($path), 'tgpart');
        if (! $src || $tmp === false) {
            throw new RuntimeException("Vaqtinchalik faylni yaratib bo'lmadi.");
        }
        try {
            @chmod($tmp, 0600);
            $dst = fopen($tmp, 'wb');
            fseek($src, $offset);
            stream_copy_to_stream($src, $dst, $length);
            fclose($dst);
            // Oqim Guzzle'ga beriladi va u yopadi (fayl allaqachon o'chirilgan bo'lsa ham ochiq oqim o'qiladi).
            $this->tg->sendDocument($chat, fopen($tmp, 'rb'), $filename, $caption);
        } finally {
            fclose($src);
            @unlink($tmp);
        }
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
