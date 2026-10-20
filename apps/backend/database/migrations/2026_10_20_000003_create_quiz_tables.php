<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->boolean('is_final')->default(false);
            $t->unsignedTinyInteger('pass_percent')->default(60);
            $t->unsignedTinyInteger('max_attempts')->default(3);
            $t->unsignedInteger('time_limit_sec')->nullable();
            $t->boolean('shuffle')->default(true);
            $t->boolean('published')->default(false);
            $t->timestamps();
        });

        Schema::create('questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['SINGLE', 'MULTIPLE', 'TEXT']);
            $t->text('text');
            $t->unsignedSmallInteger('points')->default(1);
            $t->unsignedInteger('position');
            $t->json('accepted_answers')->nullable(); // TEXT savollar uchun
            $t->timestamps();
        });

        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->text('text');
            $t->boolean('is_correct')->default(false);
            $t->unsignedInteger('position');
        });

        Schema::create('quiz_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quiz_id')->constrained();
            $t->foreignId('student_id')->constrained('users');
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('submitted_at')->nullable();
            $t->json('answers')->nullable();
            $t->unsignedInteger('score')->nullable();
            $t->unsignedInteger('max_score')->nullable();
            $t->decimal('percent', 5, 1)->nullable();
            $t->boolean('passed')->nullable();
            $t->index(['quiz_id', 'student_id']);
        });

        Schema::create('certificates', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('course_id')->constrained();
            $t->foreignId('group_id')->constrained('study_groups');
            $t->timestamp('issued_at')->useCurrent();
            $t->string('pdf_path')->nullable();
            $t->unique(['student_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
