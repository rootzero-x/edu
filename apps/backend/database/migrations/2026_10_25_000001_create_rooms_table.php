<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — xonalar ro'yxati (erkin matnli study_groups.room o'rniga).
 * study_groups.room ustuni saqlanadi: unda xona nomi takrorlanadi (bot eslatmalari {room} uchun),
 * haqiqiy bog'lanish — room_id. Mavjud erkin matnlar xonalarga aylantiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->string('note', 255)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('study_groups', function (Blueprint $t) {
            $t->foreignId('room_id')->nullable()->after('room')->constrained('rooms')->nullOnDelete();
        });

        $now = now();
        $names = DB::table('study_groups')->whereNotNull('room')->where('room', '!=', '')->distinct()->pluck('room')
            ->map(fn ($n) => trim((string) $n))->filter()->unique(fn ($n) => mb_strtolower($n));
        foreach ($names as $name) {
            $id = DB::table('rooms')->insertGetId(['name' => mb_substr($name, 0, 60), 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('study_groups')->whereRaw('lower(trim(room)) = ?', [mb_strtolower($name)])->update(['room_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('study_groups', function (Blueprint $t) {
            $t->dropConstrainedForeignId('room_id');
        });
        Schema::dropIfExists('rooms');
    }
};
