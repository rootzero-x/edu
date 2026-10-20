<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — uyga vazifa va baholar.
 *  - assignments: guruhga (ixtiyoriy: mashg'ulot yoki kurs darsiga bog'langan) vazifa, muddat bilan;
 *  - assignment_submissions: o'quvchi javobi (matn/havola/fayl) va o'qituvchi bahosi; javobsiz baho ham bo'lishi mumkin;
 *  - session_marks: mashg'ulot varaqasidagi baho (shkala — marks.scale_min..marks.scale_max).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('study_groups')->cascadeOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->dateTime('due_at')->nullable();
            $t->unsignedSmallInteger('max_score')->nullable(); // null — marks.scale_max
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('summary_sent_at')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'due_at']);
            $t->index('due_at');
        });

        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users');
            $t->text('body')->nullable();
            $t->string('link_url', 2000)->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedSmallInteger('score')->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('graded_at')->nullable();
            $t->timestamps();
            $t->unique(['assignment_id', 'student_id']);
            $t->index('student_id');
        });

        Schema::create('session_marks', function (Blueprint $t) {
            $t->foreignId('session_id')->constrained('class_sessions')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users');
            $t->unsignedSmallInteger('score');
            $t->string('note', 255)->nullable();
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('marked_at')->useCurrent();
            $t->primary(['session_id', 'student_id']);
            $t->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_marks');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};
