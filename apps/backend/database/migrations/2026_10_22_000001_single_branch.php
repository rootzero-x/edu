<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Markaz bitta filialda ishlaydi: barcha guruhlar "TISU" filialiga o'tkaziladi, qolgan filiallar o'chiriladi.
 * Ustunlar saqlanadi (ma'lumot yo'qolmaydi), foydalanuvchi filiali bo'shatiladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $primary = DB::table('branches')->whereRaw('UPPER(name) LIKE ?', ['%TISU%'])->orderBy('id')->value('id')
                ?? DB::table('branches')->orderBy('id')->value('id');
            if ($primary === null) {
                $primary = DB::table('branches')->insertGetId(['name' => 'TISU', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('branches')->where('id', $primary)->update(['name' => 'TISU', 'active' => true, 'updated_at' => now()]);
            DB::table('study_groups')->where('branch_id', '!=', $primary)->update(['branch_id' => $primary]);
            DB::table('users')->whereNotNull('branch_id')->update(['branch_id' => null]);
            DB::table('branches')->where('id', '!=', $primary)->delete();
        });
    }

    public function down(): void
    {
        // Qaytarib bo'lmaydi: eski filial bog'lanishlari saqlanmagan.
    }
};
