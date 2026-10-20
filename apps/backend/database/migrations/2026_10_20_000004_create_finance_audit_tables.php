<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yagona jurnal: INVOICE qarzni oshiradi, PAYMENT kamaytiradi, REFUND qayta oshiradi.
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('group_id')->nullable()->constrained('study_groups')->nullOnDelete();
            $t->enum('type', ['INVOICE', 'PAYMENT', 'REFUND']);
            $t->unsignedInteger('amount'); // so'm
            $t->char('period', 7)->nullable(); // "2026-10"
            $t->enum('method', ['CASH', 'CARD', 'TRANSFER', 'CLICK', 'PAYME'])->nullable();
            $t->string('external_id')->nullable()->unique();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('student_id');
            $t->index(['group_id', 'period']);
        });

        Schema::create('notifications_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 16); // telegram | web
            $t->string('kind', 64);
            $t->json('payload');
            $t->timestamp('sent_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 64);
            $t->string('entity', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('data')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity', 'entity_id']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications_log');
        Schema::dropIfExists('ledger_entries');
    }
};
