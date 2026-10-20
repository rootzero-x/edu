<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saytdan "Telegram orqali kirish": sayt kod yaratadi → bot tasdiqlaydi → sayt tokenni oladi.
        Schema::create('telegram_login_requests', function (Blueprint $t) {
            $t->string('code', 64)->primary();
            $t->string('poll_secret', 64);
            $t->enum('status', ['PENDING', 'CONFIRMED', 'CONSUMED'])->default('PENDING');
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_login_requests');
    }
};
