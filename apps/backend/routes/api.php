<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/password', [AuthController::class, 'changePassword']);

        // Foydalanuvchilar va filiallar
        Route::middleware('perm:user.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::post('/branches', [BranchController::class, 'store']);
            Route::patch('/branches/{branch}', [BranchController::class, 'update']);
        });
        Route::get('/branches', [BranchController::class, 'index']);

        // Kurslar (ko'rish)
        Route::middleware('perm:course.view')->group(function () {
            Route::get('/courses', [CourseController::class, 'index']);
            Route::get('/courses/{course}', [CourseController::class, 'show']);
            Route::get('/lessons/{lesson}', [CurriculumController::class, 'showLesson']);
        });

        // Kurslar (boshqarish)
        Route::middleware('perm:course.manage')->group(function () {
            Route::post('/courses', [CourseController::class, 'store']);
            Route::patch('/courses/{course}', [CourseController::class, 'update']);
            Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

            Route::post('/courses/{course}/modules', [CurriculumController::class, 'storeModule']);
            Route::patch('/modules/{module}', [CurriculumController::class, 'updateModule']);
            Route::delete('/modules/{module}', [CurriculumController::class, 'destroyModule']);

            Route::post('/modules/{module}/lessons', [CurriculumController::class, 'storeLesson']);
            Route::patch('/lessons/{lesson}', [CurriculumController::class, 'updateLesson']);
            Route::delete('/lessons/{lesson}', [CurriculumController::class, 'destroyLesson']);

            Route::post('/lessons/{lesson}/materials', [CurriculumController::class, 'storeMaterial']);
            Route::patch('/materials/{material}', [CurriculumController::class, 'updateMaterial']);
            Route::delete('/materials/{material}', [CurriculumController::class, 'destroyMaterial']);
        });
    });
});
