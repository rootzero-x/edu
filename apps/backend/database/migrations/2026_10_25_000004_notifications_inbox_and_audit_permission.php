<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3:
 *  - notifications_log.read_at: paneldagi qo'ng'iroqcha (o'qilgan/o'qilmagan);
 *  - audit.view huquqi: saqlangan huquqlar matritsasida ADMIN ga avtomatik qo'shiladi (faqat adminlar uchun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_log', function (Blueprint $t) {
            $t->timestamp('read_at')->nullable();
            $t->index(['user_id', 'read_at']);
            $t->index(['kind', 'created_at']);
        });

        $row = DB::table('settings')->where('key', 'roles.permissions')->first();
        if ($row) {
            $matrix = json_decode($row->value, true) ?: [];
            if (isset($matrix['ADMIN']) && is_array($matrix['ADMIN']) && ! in_array('audit.view', $matrix['ADMIN'], true)) {
                $matrix['ADMIN'][] = 'audit.view';
                DB::table('settings')->where('key', 'roles.permissions')
                    ->update(['value' => json_encode($matrix, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                Cache::forget('tisu.settings.v1');
            }
        }
    }

    public function down(): void
    {
        Schema::table('notifications_log', function (Blueprint $t) {
            $t->dropIndex(['kind', 'created_at']);
            $t->dropIndex(['user_id', 'read_at']);
            $t->dropColumn('read_at');
        });
    }
};
