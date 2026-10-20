<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — "Hech narsa yo'qolmasin":
 *  - system_status: cron yurak urishi, vazifalar holati, oxirgi zaxira;
 *  - telegram_outbox / telegram_batches: navbat orqali yuborish (cron har daqiqada bo'shatadi);
 *  - telegram_links.blocked_at: botni bloklagan chatlar;
 *  - users.pending_at: bot orqali ariza qoldirgan (admin tasdig'ini kutayotgan) akkauntlar;
 *  - certificates.revoked_*: bekor qilish o'chirish emas, holat;
 *  - questions.archived_at / replaces_id: topshirilgan testdagi savol tahriri — yangi versiya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_status', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->json('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('telegram_batches', function (Blueprint $t) {
            $t->string('id', 36)->primary();
            $t->string('kind', 64);
            $t->bigInteger('admin_chat_id')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('meta')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('telegram_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->bigInteger('chat_id');
            $t->string('kind', 64);
            $t->text('text');
            $t->json('reply_markup')->nullable();
            $t->string('batch_id', 36)->nullable()->index();
            $t->string('status', 16)->default('pending'); // pending | sent | failed | blocked
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at')->useCurrent();
            $t->string('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
            $t->index(['user_id', 'kind']);
        });

        Schema::table('telegram_links', function (Blueprint $t) {
            $t->timestamp('blocked_at')->nullable();
            $t->string('blocked_reason')->nullable();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('pending_at')->nullable()->index();
        });

        Schema::table('certificates', function (Blueprint $t) {
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason', 500)->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            // Qayta berish yangi kod bilan: bitta o'quvchi+guruh uchun bir nechta (bekor qilingan) yozuv bo'lishi mumkin.
            // Avval oddiy indeks (MySQL FK uchun kerak), keyin unique olib tashlanadi.
            $t->index(['student_id', 'group_id'], 'certificates_student_group_index');
        });
        Schema::table('certificates', function (Blueprint $t) {
            $t->dropUnique(['student_id', 'group_id']);
        });

        Schema::table('questions', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('replaces_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $t) {
            $t->dropColumn(['archived_at', 'replaces_id']);
        });
        Schema::table('certificates', function (Blueprint $t) {
            $t->unique(['student_id', 'group_id']);
        });
        Schema::table('certificates', function (Blueprint $t) {
            $t->dropIndex('certificates_student_group_index');
            $t->dropConstrainedForeignId('revoked_by');
            $t->dropColumn(['revoked_at', 'revoked_reason']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['pending_at']);
            $t->dropColumn('pending_at');
        });
        Schema::table('telegram_links', function (Blueprint $t) {
            $t->dropColumn(['blocked_at', 'blocked_reason']);
        });
        Schema::dropIfExists('telegram_outbox');
        Schema::dropIfExists('telegram_batches');
        Schema::dropIfExists('system_status');
    }
};
