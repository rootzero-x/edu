<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tashqaridan boshqariladigan sozlamalar. Qiymat: settings jadvali → bo'lmasa config/tisu.php dagi default.
 * O'qishlar keshlanadi, yozishda kesh tozalanadi.
 */
final class Settings
{
    private const CACHE_KEY = 'tisu.settings.v1';

    private ?array $loaded = null;

    public static function definitions(): array
    {
        return config('tisu.settings');
    }

    public function get(string $key): mixed
    {
        $defs = self::definitions();
        if (! isset($defs[$key])) {
            throw new \InvalidArgumentException("Noma'lum sozlama: $key");
        }
        $stored = $this->stored();

        if (! array_key_exists($key, $stored)) {
            return $defs[$key]['default'];
        }
        // Obyekt sozlamalarda keyinroq qo'shilgan standart kalitlar (masalan, yangi matnlar) ham ko'rinsin.
        if ($defs[$key]['type'] === 'object' && is_array($stored[$key]) && is_array($defs[$key]['default'])) {
            return $stored[$key] + $defs[$key]['default'];
        }

        return $stored[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(bool $publicOnly = false): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            if (! $publicOnly || ($def['public'] ?? false)) {
                $out[$key] = $this->get($key);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values, ?int $actorId): void
    {
        $defs = self::definitions();
        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            if (! isset($defs[$key])) {
                $errors[$key] = "Noma'lum sozlama.";
                continue;
            }
            // Bo'sh maydon (ConvertEmptyStringsToNull tufayli null keladi) — bo'sh satr.
            if ($value === null && in_array($defs[$key]['type'], ['string', 'text'], true)) {
                $value = '';
            }
            // Obyekt sozlamalar qisman yangilanadi: yuborilmagan kalitlar saqlanib qoladi.
            if ($defs[$key]['type'] === 'object' && is_array($value) && ! array_is_list($value)) {
                $current = (array) $this->get($key);
                if ($key === 'roles.permissions') {
                    // Endi mavjud bo'lmagan huquqlar (eski versiyadan qolgan) yangi qiymatni rad ettirmasin.
                    $known = config('tisu.permissions');
                    $current = array_map(fn ($perms) => array_values(array_intersect((array) $perms, $known)), $current);
                }
                $value = array_replace($current, $value);
            }
            $err = $this->validate($key, $defs[$key], $value);
            $err ? $errors[$key] = $err : $clean[$key] = $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($clean, $actorId) {
            if (array_key_exists('billing.monthly_fee', $clean)) {
                Billing::recordFeeChange($this->int('billing.monthly_fee'), (int) $clean['billing.monthly_fee'], $actorId);
            }
            foreach ($clean as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $actorId, 'updated_at' => now()],
                );
            }
        });
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    /** Sozlamani default qiymatga qaytarish. */
    public function reset(string $key): void
    {
        DB::transaction(function () use ($key) {
            if ($key === 'billing.monthly_fee') {
                Billing::recordFeeChange($this->int($key), (int) self::definitions()[$key]['default'], request()?->user()?->id);
            }
            DB::table('settings')->where('key', $key)->delete();
        });
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    private function stored(): array
    {
        return $this->loaded ??= Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))->all());
    }

    private function validate(string $key, array $def, mixed $v): ?string
    {
        switch ($def['type']) {
            case 'int':
                if (! is_int($v)) {
                    return 'Butun son bo\'lishi kerak.';
                }
                if ((isset($def['min']) && $v < $def['min']) || (isset($def['max']) && $v > $def['max'])) {
                    return "Qiymat {$def['min']}..{$def['max']} oralig'ida bo'lishi kerak.";
                }

                return null;
            case 'bool':
                return is_bool($v) ? null : 'true yoki false bo\'lishi kerak.';
            case 'string':
                if (! is_string($v) || mb_strlen($v) > 500) {
                    return 'Satr (500 belgigacha) bo\'lishi kerak.';
                }
                // format: date — YYYY-MM-DD yoki bo'sh satr.
                if (($def['format'] ?? null) === 'date' && $v !== ''
                    && (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) === false || $d->format('Y-m-d') !== $v)) {
                    return "Sana YYYY-MM-DD formatida bo'lishi kerak.";
                }

                return null;
            case 'text':
                return is_string($v) && mb_strlen($v) <= 20000 ? null : 'Matn (20000 belgigacha) bo\'lishi kerak.';
            case 'list':
                if (! is_array($v) || ! array_is_list($v)) {
                    return 'Ro\'yxat bo\'lishi kerak.';
                }
                if (($def['items'] ?? null) === 'int' && array_filter($v, fn ($i) => ! is_int($i))) {
                    return 'Ro\'yxat faqat butun sonlardan iborat bo\'lishi kerak.';
                }

                return null;
            case 'object':
                if (! is_array($v) || ($v !== [] && array_is_list($v))) {
                    return 'Obyekt bo\'lishi kerak.';
                }
                if ($key === 'roles.permissions') {
                    return $this->validatePermissions($v);
                }

                return null;
        }

        return 'Noma\'lum tur.';
    }

    private function validatePermissions(array $v): ?string
    {
        $known = config('tisu.permissions');
        foreach ($v as $role => $perms) {
            if (! in_array($role, ['ADMIN', 'TEACHER', 'STUDENT', 'PARENT'], true)) {
                return "Noma'lum rol: $role (SUPER_ADMIN huquqlari o'zgarmaydi).";
            }
            if (! is_array($perms) || array_diff($perms, $known)) {
                return "$role uchun noma'lum huquq: ".implode(', ', array_diff((array) $perms, $known));
            }
        }

        return null;
    }
}
