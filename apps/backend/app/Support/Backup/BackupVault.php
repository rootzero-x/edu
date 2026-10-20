<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Zaxira fayllarini shifrlash (AES-256-GCM, oqim bo'yicha — katta fayl xotiraga to'liq yuklanmaydi).
 *
 * Kalit: .env dagi BACKUP_ENCRYPTION_KEY (config/backup.php → backup.key). Repo'da hech qachon saqlanmaydi.
 *   - "base64:..." — 32 baytli kalit (php artisan tisu:backup-key bilan yaratiladi);
 *   - boshqa satr — parol ibora, undan PBKDF2-SHA256 (200 000 iteratsiya, tasodifiy tuz) bilan kalit olinadi.
 *
 * Fayl formati (.enc):
 *   "TISUBAK1" (8) | kdf (1: 0 — xom kalit, 1 — PBKDF2) | tuz (16) | asosiy nonce (8)
 *   keyin bo'laklar: [uzunlik (4, big-endian)] [shifrlangan matn] [GCM tag (16)]
 *   Har bo'lak nonce = asosiy nonce (8) + bo'lak raqami (4, big-endian); oxirgi bo'lak AAD sida "last" belgisi —
 *   fayl kesib qo'yilgan (truncated) bo'lsa ochishda xato beradi.
 */
final class BackupVault
{
    public const MAGIC = 'TISUBAK1';

    private const CHUNK = 1048576; // 1 MiB

    private const PBKDF2_ITERATIONS = 200000;

    public static function keyConfigured(): bool
    {
        return trim((string) config('backup.key')) !== '';
    }

    /** Yangi tasodifiy kalit (.env uchun). */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (! $h) {
            return false;
        }
        $magic = fread($h, strlen(self::MAGIC));
        fclose($h);

        return $magic === self::MAGIC;
    }

    /** $in → $out (shifrlangan). */
    public static function encrypt(string $in, string $out, ?string $secret = null): void
    {
        [$kdf, $salt, $key] = self::deriveForEncrypt($secret ?? (string) config('backup.key'));
        $src = self::open($in, 'rb');
        $dst = self::open($out, 'wb');
        try {
            $nonceBase = random_bytes(8);
            fwrite($dst, self::MAGIC.chr($kdf).$salt.$nonceBase);
            $index = 0;
            $chunk = fread($src, self::CHUNK);
            do {
                $next = feof($src) ? '' : fread($src, self::CHUNK);
                $last = $next === '' || $next === false;
                $tag = '';
                $cipher = openssl_encrypt((string) $chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                    $nonceBase.pack('N', $index), $tag, self::aad($index, $last), 16);
                if ($cipher === false) {
                    throw new RuntimeException('Shifrlashda xato (openssl).');
                }
                fwrite($dst, pack('N', strlen($cipher)).$cipher.$tag);
                $index++;
                $chunk = $next;
            } while (! $last);
        } finally {
            fclose($src);
            fclose($dst);
        }
        @chmod($out, 0600);
    }

    /** $in (shifrlangan) → $out. Kalit noto'g'ri yoki fayl buzilgan/kesilgan bo'lsa xato. */
    public static function decrypt(string $in, string $out, ?string $secret = null): void
    {
        $src = self::open($in, 'rb');
        $dst = self::open($out, 'wb');
        $ok = false;
        try {
            $header = fread($src, strlen(self::MAGIC) + 1 + 16 + 8);
            if (strlen((string) $header) !== 33 || ! str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('Bu TISU shifrlangan zaxira fayli emas.');
            }
            $kdf = ord($header[8]);
            $salt = substr($header, 9, 16);
            $nonceBase = substr($header, 25, 8);
            $key = self::deriveForDecrypt($secret ?? (string) config('backup.key'), $kdf, $salt);
            $index = 0;
            $sawLast = false;
            while (! feof($src)) {
                $lenRaw = fread($src, 4);
                if ($lenRaw === '' || $lenRaw === false) {
                    break;
                }
                if ($sawLast || strlen($lenRaw) !== 4) {
                    throw new RuntimeException("Fayl buzilgan (ortiqcha ma'lumot).");
                }
                $len = unpack('N', $lenRaw)[1];
                $cipher = $len > 0 ? self::readExact($src, $len) : '';
                $tag = self::readExact($src, 16);
                // Avval "oxirgi emas" deb, bo'lmasa "oxirgi" deb ochamiz — kesilgan fayl aniqlanadi.
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonceBase.pack('N', $index), $tag, self::aad($index, false));
                if ($plain === false) {
                    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonceBase.pack('N', $index), $tag, self::aad($index, true));
                    if ($plain === false) {
                        throw new RuntimeException("Ochib bo'lmadi: kalit noto'g'ri yoki fayl buzilgan.");
                    }
                    $sawLast = true;
                }
                fwrite($dst, $plain);
                $index++;
            }
            if (! $sawLast) {
                throw new RuntimeException("Fayl to'liq emas (oxiri kesilgan). Barcha bo'laklar ketma-ket birlashtirilganini tekshiring.");
            }
            $ok = true;
        } finally {
            fclose($src);
            fclose($dst);
            if (! $ok) {
                @unlink($out);
            }
        }
        @chmod($out, 0600);
    }

    private static function aad(int $index, bool $last): string
    {
        return self::MAGIC.pack('N', $index).($last ? 'last' : 'more');
    }

    /** @return array{0: int, 1: string, 2: string} kdf, tuz, kalit */
    private static function deriveForEncrypt(string $secret): array
    {
        $salt = random_bytes(16);
        if (($raw = self::rawKey($secret)) !== null) {
            return [0, $salt, $raw];
        }

        return [1, $salt, self::pbkdf2($secret, $salt)];
    }

    private static function deriveForDecrypt(string $secret, int $kdf, string $salt): string
    {
        if ($kdf === 0) {
            $raw = self::rawKey($secret);
            if ($raw === null) {
                throw new RuntimeException("Fayl base64 kalit bilan shifrlangan, lekin BACKUP_ENCRYPTION_KEY base64:... emas.");
            }

            return $raw;
        }

        return self::pbkdf2($secret, $salt);
    }

    private static function rawKey(string $secret): ?string
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY .env da berilmagan.');
        }
        if (str_starts_with($secret, 'base64:')) {
            $raw = base64_decode(substr($secret, 7), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException("BACKUP_ENCRYPTION_KEY noto'g'ri: base64:... 32 bayt bo'lishi kerak.");
            }

            return $raw;
        }

        return null;
    }

    private static function pbkdf2(string $secret, string $salt): string
    {
        if (strlen(trim($secret)) < 16) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY parol ibora sifatida kamida 16 belgi bo\'lishi kerak.');
        }

        return hash_pbkdf2('sha256', trim($secret), $salt, self::PBKDF2_ITERATIONS, 32, true);
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $h = @fopen($path, $mode);
        if (! $h) {
            throw new RuntimeException("Faylni ochib bo'lmadi: $path");
        }

        return $h;
    }

    /** @param resource $h */
    private static function readExact($h, int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len && ! feof($h)) {
            $part = fread($h, $len - strlen($buf));
            if ($part === false || $part === '') {
                break;
            }
            $buf .= $part;
        }
        if (strlen($buf) !== $len) {
            throw new RuntimeException("Fayl to'liq emas (oxiri kesilgan).");
        }

        return $buf;
    }
}
