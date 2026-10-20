<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markaz faqat o'quvchilar bilan ishlaydi: PARENT roli, ota-ona ↔ farzand bog'lanishi va ota-onaga
 * xabarlar olib tashlanadi.
 *  - PARENT rolidagi foydalanuvchilar (bo'lsa) o'chirilmaydi: nofaol STUDENT qilinadi, tokenlari bekor qilinadi;
 *  - parent_child jadvali o'chiriladi;
 *  - MySQL/MariaDB'da users.role enum'idan PARENT olib tashlanadi;
 *  - endi mavjud bo'lmagan sozlamalar va obyekt sozlamalardagi PARENT/farzand kalitlari tozalanadi.
 */
return new class extends Migration
{
    /** Butunlay olib tashlangan sozlamalar. */
    private const REMOVED_SETTINGS = [
        'attendance.notify_parents', 'attendance.notify_only_same_day', 'attendance.notify_statuses',
        'billing.notify_parents', 'automation.lesson_reminder_parents',
        'telegram.text.attendance_parent', 'telegram.text.billing_payment_parent', 'telegram.text.billing_debt_reminder_parent',
        'telegram.text.btn_children', 'telegram.text.marked_notified', 'telegram.text.children_title', 'telegram.text.child_block',
        'telegram.text.child_absences', 'telegram.text.child_no_groups', 'telegram.text.children_empty',
    ];

    /** Obyekt sozlamalardan olib tashlanadigan kalitlar. */
    private const REMOVED_OBJECT_KEYS = [
        'roles.permissions' => ['PARENT'],
        'telegram.menu' => ['PARENT'],
        'telegram.role_labels' => ['PARENT'],
        'telegram.text.admin_audience_labels' => ['PARENT'],
        'miniapp.texts' => ['nav_children', 'children_empty', 'my_children', 'sheet_saved_notified'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $parents = DB::table('users')->where('role', 'PARENT')->pluck('id');
            if ($parents->isNotEmpty()) {
                DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)
                    ->whereIn('tokenable_id', $parents)->delete();
                DB::table('users')->whereIn('id', $parents)->update(['role' => 'STUDENT', 'active' => false, 'updated_at' => now()]);
            }
            $this->cleanSettings();
        });
        Schema::dropIfExists('parent_child');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('SUPER_ADMIN', 'ADMIN', 'TEACHER', 'STUDENT') NOT NULL");
        }
        Cache::forget('tisu.settings.v1');
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('SUPER_ADMIN', 'ADMIN', 'TEACHER', 'STUDENT', 'PARENT') NOT NULL");
        }
        if (! Schema::hasTable('parent_child')) {
            Schema::create('parent_child', function (Blueprint $t) {
                $t->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('child_id')->constrained('users')->cascadeOnDelete();
                $t->primary(['parent_id', 'child_id']);
            });
        }
        // O'chirilgan sozlamalar va foydalanuvchi rollari qaytarilmaydi (standart qiymatlar config'dan olinadi).
    }

    private function cleanSettings(): void
    {
        DB::table('settings')->whereIn('key', self::REMOVED_SETTINGS)->delete();

        $rows = DB::table('settings')->whereIn('key', [...array_keys(self::REMOVED_OBJECT_KEYS), 'telegram.profile.commands'])->get(['key', 'value']);
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (! is_array($value)) {
                continue;
            }
            if ($row->key === 'telegram.profile.commands') {
                // Har bir til: [{command, description}] — /children buyrug'i olib tashlanadi.
                $clean = array_map(fn ($list) => is_array($list)
                    ? array_values(array_filter($list, fn ($c) => ($c['command'] ?? null) !== 'children'))
                    : $list, $value);
            } else {
                $clean = array_diff_key($value, array_flip(self::REMOVED_OBJECT_KEYS[$row->key]));
            }
            if ($row->key === 'telegram.menu') {
                // Qolgan rollar menyusidan ham "children" tugmasi olib tashlanadi.
                $clean = array_map(fn ($rowsOfRole) => is_array($rowsOfRole)
                    ? array_values(array_filter(array_map(fn ($r) => is_array($r) ? array_values(array_diff($r, ['children'])) : $r, $rowsOfRole)))
                    : $rowsOfRole, $clean);
            }
            if ($clean !== $value) {
                DB::table('settings')->where('key', $row->key)->update([
                    'value' => json_encode($clean === [] ? (object) [] : $clean, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
                ]);
            }
        }
    }
};
