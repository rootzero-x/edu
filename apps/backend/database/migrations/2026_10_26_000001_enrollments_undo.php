<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yopilgan a'zolik qatorida "bugungi chiqarishni bekor qilish" uchun asl holat (o'chirilgan rejalashtirilgan qatorlar). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $t) {
            $t->text('undo')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $t) {
            $t->dropColumn('undo');
        });
    }
};
