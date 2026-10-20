<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('kind', ['PERCENT', 'FIXED']);
            $t->unsignedInteger('value');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('study_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('course_id')->constrained();
            $t->foreignId('branch_id')->constrained('branches');
            $t->foreignId('teacher_id')->constrained('users');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->string('room')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('group_members', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained('study_groups')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $t->timestamp('joined_at')->useCurrent();
            $t->timestamp('left_at')->nullable();
            $t->primary(['group_id', 'student_id']);
            $t->index('student_id');
        });

        // Haftalik jadval: weekday 1=Dushanba … 7=Yakshanba, vaqt Asia/Tashkent.
        Schema::create('group_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('study_groups')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->time('start_time');
            $t->time('end_time');
        });

        Schema::create('class_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('study_groups')->cascadeOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('topic')->nullable();
            $t->boolean('cancelled')->default(false);
            $t->timestamps();
            $t->unique(['group_id', 'starts_at']);
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->foreignId('session_id')->constrained('class_sessions')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users');
            $t->enum('status', ['PRESENT', 'LATE', 'ABSENT', 'EXCUSED']);
            $t->dateTime('arrived_at')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('marked_by')->constrained('users');
            $t->timestamp('marked_at')->useCurrent();
            $t->primary(['session_id', 'student_id']);
            $t->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('group_schedules');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('study_groups');
        Schema::dropIfExists('discounts');
    }
};
