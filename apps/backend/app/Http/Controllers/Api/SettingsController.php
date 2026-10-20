<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController
{
    /** Login talab qilmaydi: sayt nomi, matnlar, rang va h.k. */
    public function public(Settings $settings): JsonResponse
    {
        return response()->json(['settings' => $settings->all(publicOnly: true) + ['app.timezone' => config('app.timezone')]])
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** Barcha sozlamalar: qiymat, default, tur, guruh, izoh — admin panel formasi shundan quriladi. */
    public function index(Settings $settings): JsonResponse
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
                'default' => $def['default'],
                'value' => $settings->get($key),
            ];
        }

        return response()->json(['data' => $items, 'permissions' => config('tisu.permissions')]);
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $values = $request->validate(['values' => ['required', 'array', 'min:1']])['values'];
        $settings->set($values, $request->user()->id);
        Audit::log('settings.update', 'settings', null, $values);

        return response()->json(['ok' => true, 'values' => array_intersect_key($settings->all(), $values)]);
    }

    public function reset(Request $request, Settings $settings, string $key): JsonResponse
    {
        abort_unless(array_key_exists($key, Settings::definitions()), 404);
        $settings->reset($key);
        Audit::log('settings.reset', 'settings', null, ['key' => $key]);

        return response()->json(['ok' => true, 'value' => $settings->get($key)]);
    }
}
