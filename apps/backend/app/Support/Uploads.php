<?php

namespace App\Support;

use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fayl yuklash (shared hosting: navbat va tashqi saqlash yo'q).
 *  - Fayllar "uploads" diskida (storage/app/private/uploads) — veb orqali to'g'ridan-to'g'ri ochilmaydi.
 *  - Tur kengaytma bo'yicha emas, fayl ichidan (finfo + imzo) tekshiriladi; dastur va skriptlar rad etiladi.
 *  - Yuklab olish — imzolangan, muddatli havola (uploads.link_ttl_minutes); havolani faqat ruxsati bor foydalanuvchi oladi.
 *  - Chegaralar va turlar sozlamalarda: uploads.*.
 */
final class Uploads
{
    public const DISK = 'uploads';

    /** Sozlamada yozilsa ham hech qachon qabul qilinmaydigan kengaytmalar. */
    private const NEVER = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'ps1', 'vbs', 'vbe', 'js', 'mjs', 'jse', 'wsf', 'jar', 'apk', 'app', 'dmg',
        'html', 'htm', 'xhtml', 'shtml', 'xml', 'svgz', 'htaccess', 'htpasswd', 'ini', 'swf', 'hta', 'lnk', 'reg', 'docm', 'xlsm', 'pptm',
    ];

    /** Kengaytma → fayl ichidan aniqlangan (finfo) ruxsat etilgan MIME turlari. */
    private const MIMES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/msword'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
        'mp3' => ['audio/mpeg', 'audio/mp3'], 'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'], 'mp4' => ['video/mp4'],
    ];

    /** Saqlanadigan (va yuklab olishda yuboriladigan) aniq Content-Type. */
    private const SERVE_AS = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain; charset=UTF-8', 'csv' => 'text/csv; charset=UTF-8', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'video/mp4',
    ];

    /** Brauzerda ochiladigan turlar (qolganlari yuklab olinadi). */
    private const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp3', 'm4a', 'mp4'];

    public function __construct(private Settings $settings) {}

    /**
     * Zaxira nusxaga kiritilishi kerak bo'lgan papkalar (BackupCommand / Sprint 1 shifrlangan zaxira shu ro'yxatni oladi).
     *
     * @return list<string>
     */
    public static function backupPaths(): array
    {
        return [rtrim((string) config('filesystems.disks.'.self::DISK.'.root'), '/')];
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $list = array_map(fn ($e) => strtolower(ltrim(trim((string) $e), '.')), (array) $this->settings->get('uploads.allowed_types'));

        return array_values(array_filter(array_unique($list), fn ($e) => isset(self::MIMES[$e]) && ! in_array($e, self::NEVER, true)));
    }

    /** Hozir band joy (bayt) va kvota. */
    public function usage(): array
    {
        $quota = $this->settings->int('uploads.quota_mb');

        return [
            'files' => (int) DB::table('files')->count(),
            'bytes' => (int) DB::table('files')->sum('size'),
            'quota_bytes' => $quota > 0 ? $quota * 1024 * 1024 : null,
            'max_bytes' => $this->settings->int('uploads.max_mb') * 1024 * 1024,
            'allowed_types' => $this->allowedExtensions(),
            'paths' => self::backupPaths(),
        ];
    }

    /** Tekshirish va saqlash. Xato bo'lsa — 422 (field: file). */
    public function store(UploadedFile $file, string $ownerType, ?int $ownerId, ?int $userId): StoredFile
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['file' => $msg]);
        if (! $this->settings->bool('uploads.enabled')) {
            $fail("Fayl yuklash o'chirilgan.");
        }
        if (! $file->isValid()) {
            $fail("Fayl yuklanmadi (hajmi server chegarasidan katta bo'lishi mumkin).");
        }
        $maxMb = $this->settings->int('uploads.max_mb');
        $size = (int) $file->getSize();
        if ($size <= 0) {
            $fail("Fayl bo'sh.");
        }
        if ($size > $maxMb * 1024 * 1024) {
            $fail("Fayl hajmi {$maxMb} MB dan oshmasligi kerak.");
        }
        $usage = $this->usage();
        if ($usage['quota_bytes'] !== null && $usage['bytes'] + $size > $usage['quota_bytes']) {
            $fail("Fayllar uchun ajratilgan joy to'lgan. Administratorga murojaat qiling.");
        }
        $name = self::cleanName($file->getClientOriginalName());
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $this->allowedExtensions();
        if ($ext === '' || in_array($ext, self::NEVER, true) || ! in_array($ext, $allowed, true)) {
            $fail('Bu turdagi fayl qabul qilinmaydi. Ruxsat etilgan: '.implode(', ', $allowed).'.');
        }
        $error = self::inspect((string) $file->getRealPath(), $ext);
        if ($error) {
            $fail($error);
        }

        $path = now()->format('Y/m').'/'.Str::random(40).'.'.$ext;
        $stream = fopen((string) $file->getRealPath(), 'rb');
        try {
            Storage::disk(self::DISK)->put($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        return StoredFile::create([
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'path' => $path, 'original_name' => $name,
            'mime' => self::SERVE_AS[$ext], 'size' => $size, 'sha256' => hash_file('sha256', (string) $file->getRealPath()),
            'uploaded_by' => $userId,
        ]);
    }

    public function delete(StoredFile $file): void
    {
        Storage::disk(self::DISK)->delete($file->path);
        $file->delete();
    }

    /** Egasi o'chirilganda uning barcha fayllari. */
    public function deleteOwned(string $ownerType, array $ownerIds): void
    {
        StoredFile::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->get()->each(fn (StoredFile $f) => $this->delete($f));
    }

    /** Imzolangan, muddatli yuklab olish havolasi (domen — APP_URL; imzo nisbiy yo'l bo'yicha, proksi orqasida ham ishlaydi). */
    public function link(StoredFile $file): array
    {
        $expires = now()->addMinutes($this->settings->int('uploads.link_ttl_minutes'));
        $relative = URL::temporarySignedRoute('files.download', $expires, ['file' => $file->id], absolute: false);

        return ['url' => rtrim((string) config('app.url'), '/').$relative, 'expires_at' => $expires->toIso8601String()] + $file->toApi();
    }

    public static function inline(StoredFile $file): bool
    {
        return in_array(strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)), self::INLINE, true);
    }

    /**
     * Fayl ichini tekshirish. @return string|null xato matni
     */
    public static function inspect(string $path, string $ext): ?string
    {
        $bad = "Fayl turi kengaytmasiga mos emas yoki xavfli tarkibga ega.";
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return "Faylni o'qib bo'lmadi.";
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);

        // Bajariladigan fayllar (Windows PE, Linux ELF, macOS Mach-O, skript "#!").
        foreach (["MZ", "\x7fELF", "#!", "\xCA\xFE\xBA\xBE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE"] as $magic) {
            if (str_starts_with($head, $magic)) {
                return $bad;
            }
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (! in_array($mime, self::MIMES[$ext] ?? [], true)) {
            return $bad;
        }
        // Butun faylda PHP kodi (rasm ichiga yashirilgan "polyglot" ham) bo'lmasin.
        if (self::contains($path, ['<?php', '<?=', '<%@'])) {
            return $bad;
        }
        if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            $dir = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
            if (! str_starts_with($head, "PK\x03\x04") || ! self::contains($path, ['[Content_Types].xml'], all: true)
                || ! self::contains($path, [$dir]) || self::contains($path, ['vbaProject.bin'])) {
                return $bad;
            }
        }
        if (in_array($ext, ['odt', 'ods', 'odp'], true) && (! str_starts_with($head, "PK\x03\x04") || ! str_contains($head, 'application/vnd.oasis.opendocument'))) {
            return $bad;
        }
        if ($ext === 'svg') {
            $svg = strtolower((string) file_get_contents($path));
            if (! str_contains($svg, '<svg') || preg_match('/<script|javascript:|\son[a-z]+\s*=|<foreignobject|<iframe|<embed|<object|xlink:href\s*=\s*["\']\s*(?!#)|<!entity/i', $svg)) {
                return 'SVG faylda skript yoki tashqi havola bor — qabul qilinmaydi.';
            }
        }

        return null;
    }

    /** Faylda satrlardan biri (all: hammasi) bormi — bo'laklab o'qiladi (katta fayl xotiraga to'liq yuklanmaydi). */
    private static function contains(string $path, array $needles, bool $all = false): bool
    {
        $found = [];
        $overlap = max(array_map('strlen', $needles)) - 1;
        $fh = fopen($path, 'rb');
        $tail = '';
        while (! feof($fh)) {
            $chunk = $tail.fread($fh, 1 << 20);
            foreach ($needles as $n) {
                if (! isset($found[$n]) && stripos($chunk, $n) !== false) {
                    $found[$n] = true;
                }
            }
            if (! $all && $found) {
                break;
            }
            $tail = substr($chunk, -$overlap);
        }
        fclose($fh);

        return $all ? count($found) === count($needles) : (bool) $found;
    }

    /** Asl nomdan yo'l belgilari va boshqaruv belgilarini olib tashlash. */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f"<>|:*?]/u', '', $name) ?? '';
        $name = trim($name, " .");

        return mb_substr($name !== '' ? $name : 'fayl', -200);
    }
}
