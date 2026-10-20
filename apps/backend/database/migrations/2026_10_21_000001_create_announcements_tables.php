<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('body');
            $t->string('image_url', 1000)->nullable();
            $t->enum('audience', ['ALL', 'ROLE', 'GROUP'])->default('ALL');
            $t->string('audience_role', 20)->nullable();
            $t->foreignId('group_id')->nullable()->constrained('study_groups')->cascadeOnDelete();
            $t->boolean('is_public')->default(false);
            $t->boolean('pinned')->default(false);
            $t->boolean('notify_telegram')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['published_at', 'audience']);
        });

        Schema::create('announcement_reads', function (Blueprint $t) {
            $t->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->useCurrent();
            $t->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
