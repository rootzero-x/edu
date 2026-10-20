<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('cover_url')->nullable();
            $t->unsignedInteger('monthly_fee')->default(0); // so'm
            $t->unsignedSmallInteger('duration_months')->default(1);
            $t->boolean('published')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('course_modules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->index(['course_id', 'position']);
        });

        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('module_id')->constrained('course_modules')->cascadeOnDelete();
            $t->string('title');
            $t->longText('body')->nullable();
            $t->unsignedInteger('position');
            $t->boolean('published')->default(false);
            $t->timestamps();
            $t->index(['module_id', 'position']);
        });

        Schema::create('lesson_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['VIDEO', 'FILE', 'LINK', 'TEXT']);
            $t->string('title');
            $t->text('url')->nullable();
            $t->longText('content')->nullable();
            $t->unsignedInteger('position');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_materials');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
    }
};
