<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsController
{
    /** Bu huquqlarni yo'qotish administratorni tizimdan "qulflab" qo'yadi. */
    private const LOCKOUT_PERMISSIONS = ['settings.manage', 'user.manage'];

    /** Login talab qilmaydi: sayt nomi, matnlar, rang va h.k. */
    public function public(Settings $settings): JsonResponse
    {
        return response()->json(['settings' => $settings->all(publicOnly: true) + ['app.timezone' => config('app.timezone')]])
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** Barcha sozlamalar: qiymat, default, tur, guruh, izoh — admin panel formasi shundan quriladi. */
    public function index(Request $request, Settings $settings): JsonResponse
    {
        $items = [];
        foreach (Settings::definitions() as $key => $def) {
            $items[] = [
                'key' => $key,
                'group' => $def['group'],
                'type' => $def['type'],
                'label' => $def['label'],
                'public' => $def['public'] ?? false,
                'min' => $def['min'] ?? null,
                'max' => $def['max'] ?? null,
                'format' => $def['format'] ?? null,
                'items' => $def['items'] ?? null,
                'default' => $def['default'],
                'value' => $settings->get($key),
            ];
        }

        return response()->json([
            'data' => $items,
            'permissions' => config('tisu.permissions'),
            'permission_labels' => (array) config('tisu.permission_labels', []),
            'admin_only_permissions' => Settings::ADMIN_ONLY_PERMISSIONS,
            'groups' => config('tisu.groups'),
            // Kalitlar va huquq kodlari faqat super-adminga ko'rsatiladi (boshqalarga — odam o'qiydigan nomlar).
            'show_keys' => $request->user()->role === Role::SuperAdmin,
        ]);
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $values = $request->validate(['values' => ['required', 'array', 'min:1']])['values'];
        if (array_key_exists('roles.permissions', $values) && is_array($values['roles.permissions'])) {
            $this->guardLockout($request, $settings, $values['roles.permissions']);
        }
        $before = array_map(fn ($k) => array_key_exists($k, Settings::definitions()) ? $settings->get($k) : null, array_combine(array_keys($values), array_keys($values)));
        $settings->set($values, $request->user()->id);
        $after = array_intersect_key($settings->all(), $values);
        Audit::log('settings.update', 'settings', null, ['before' => $before, 'after' => $after]);

        return response()->json(['ok' => true, 'values' => $after]);
    }

    public function reset(Request $request, Settings $settings, string $key): JsonResponse
    {
        abort_unless(array_key_exists($key, Settings::definitions()), 404);
        if ($key === 'roles.permissions') {
            $this->guardLockout($request, $settings, (array) Settings::definitions()[$key]['default']);
        }
        $before = $settings->get($key);
        $settings->reset($key);
        Audit::log('settings.reset', 'settings', null, ['key' => $key, 'before' => $before, 'after' => $settings->get($key)]);

        return response()->json(['ok' => true, 'value' => $settings->get($key)]);
    }

    /**
     * Huquqlar matritsasi o'zgarganda o'zini qulflab qo'yishdan himoya:
     *  - super-admin bo'lmagan foydalanuvchi o'z rolidan settings.manage / user.manage ni olib tashlay olmaydi;
     *  - faol super-admin bo'lmasa, bu huquqlar faol foydalanuvchisi bor oxirgi roldan olib tashlanmaydi.
     */
    private function guardLockout(Request $request, Settings $settings, array $incoming): void
    {
        $current = (array) $settings->get('roles.permissions');
        $next = array_replace($current, array_filter($incoming, 'is_array'));
        $actor = $request->user();
        $errors = [];
        foreach (self::LOCKOUT_PERMISSIONS as $perm) {
            if ($actor->role !== Role::SuperAdmin) {
                $role = $actor->role->value;
                if (in_array($perm, (array) ($current[$role] ?? []), true) && ! in_array($perm, (array) ($next[$role] ?? []), true)) {
                    $errors[] = "«{$perm}» huquqini o'z rolingizdan olib tashlasangiz, bu sahifaga kira olmay qolasiz.";
                    continue;
                }
            }
            if (DB::table('users')->where('role', Role::SuperAdmin->value)->where('active', true)->exists()) {
                continue;
            }
            $hadHolder = false;
            $hasHolder = false;
            foreach (['ADMIN', 'TEACHER', 'STUDENT'] as $role) {
                $active = DB::table('users')->where('role', $role)->where('active', true)->exists();
                $hadHolder = $hadHolder || ($active && in_array($perm, (array) ($current[$role] ?? []), true));
                $hasHolder = $hasHolder || ($active && in_array($perm, (array) ($next[$role] ?? []), true));
            }
            if ($hadHolder && ! $hasHolder) {
                $errors[] = "«{$perm}» huquqi faol foydalanuvchisi bor oxirgi roldan olib tashlanmaydi — tizimni boshqaradigan hech kim qolmaydi.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['roles.permissions' => $errors]);
        }
    }
}
