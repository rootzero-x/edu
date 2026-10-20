<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — yuklangan fayllar (dars materiallari, uyga vazifa va javoblar).
 * Fayllarning o'zi storage/app/private/uploads da (App\Support\Uploads), bu yerda — metama'lumot.
 * owner_type: material | assignment | submission; owner_id — shu yozuv id'si.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $t) {
            $t->id();
            $t->string('owner_type', 24);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('path', 255)->unique();
            $t->string('original_name', 255);
            $t->string('mime', 127);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['owner_type', 'owner_id']);
        });

        Schema::table('lesson_materials', function (Blueprint $t) {
            $t->foreignId('file_id')->nullable()->after('url')->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_materials', function (Blueprint $t) {
            $t->dropConstrainedForeignId('file_id');
        });
        Schema::dropIfExists('files');
    }
};
