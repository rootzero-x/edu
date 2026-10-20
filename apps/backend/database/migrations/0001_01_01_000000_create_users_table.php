<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 32)->unique();
            $t->string('password')->nullable();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->enum('role', ['SUPER_ADMIN', 'ADMIN', 'TEACHER', 'STUDENT', 'PARENT'])->index();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->boolean('active')->default(true);
            $t->string('locale', 5)->default('uz');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('parent_child', function (Blueprint $t) {
            $t->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('child_id')->constrained('users')->cascadeOnDelete();
            $t->primary(['parent_id', 'child_id']);
        });

        Schema::create('telegram_links', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->bigInteger('telegram_id')->unique();
            $t->bigInteger('chat_id');
            $t->string('username')->nullable();
            $t->timestamp('linked_at')->useCurrent();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('telegram_links');
        Schema::dropIfExists('parent_child');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
    }
};
