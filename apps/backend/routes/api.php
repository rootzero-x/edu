<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TelegramAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/telegram/start', [TelegramAuthController::class, 'start'])->middleware('throttle:login');
    Route::post('/auth/telegram/poll', [TelegramAuthController::class, 'poll'])->middleware('throttle:api');
    Route::post('/auth/telegram/miniapp', [TelegramAuthController::class, 'miniApp'])->middleware('throttle:login');
    Route::post('/telegram/webhook', [TelegramAuthController::class, 'webhook']);
    Route::get('/public/certificates/{code}', [CertificateController::class, 'verify'])->middleware('throttle:api');
    Route::get('/public/settings', [SettingsController::class, 'public'])->middleware('throttle:api');
    Route::get('/public/courses', [LandingController::class, 'courses'])->middleware('throttle:api');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/password', [AuthController::class, 'changePassword']);
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        // Sozlamalar (faqat settings.manage — standart: SUPER_ADMIN)
        Route::middleware('perm:settings.manage')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::patch('/settings', [SettingsController::class, 'update']);
            Route::delete('/settings/{key}', [SettingsController::class, 'reset']);
        });

        // Foydalanuvchilar va filiallar
        Route::middleware('perm:user.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::post('/branches', [BranchController::class, 'store']);
            Route::patch('/branches/{branch}', [BranchController::class, 'update']);
        });
        Route::get('/branches', [BranchController::class, 'index']);
        Route::middleware('perm:user.manage')->group(function () {
            Route::post('/users/{parent}/children', [UserController::class, 'linkChild']);
            Route::delete('/users/{parent}/children/{child}', [UserController::class, 'unlinkChild']);
        });

        // Guruhlar va davomat (ko'rish — ko'rinish doirasi controller'da)
        Route::get('/groups', [GroupController::class, 'index']);
        Route::get('/groups/{group}', [GroupController::class, 'show'])->whereNumber('group');
        Route::get('/groups/{group}/sessions', [GroupController::class, 'sessions'])->whereNumber('group');
        Route::get('/groups/{group}/attendance', [AttendanceController::class, 'report'])->whereNumber('group');
        Route::get('/sessions/{session}/attendance', [AttendanceController::class, 'sheet']);
        Route::middleware('perm:attendance.mark')->group(function () {
            Route::get('/sessions/{session}/attendance/history', [AttendanceController::class, 'history']);
            Route::get('/attendance/unmarked', [AttendanceController::class, 'unmarked']);
        });
        Route::middleware('perm:attendance.mark')->group(function () {
            Route::put('/sessions/{session}/attendance', [AttendanceController::class, 'mark']);
            Route::patch('/sessions/{session}', [GroupController::class, 'updateSession']);
        });
        Route::middleware('perm:group.manage')->group(function () {
            Route::post('/groups', [GroupController::class, 'store']);
            Route::patch('/groups/{group}', [GroupController::class, 'update']);
            Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
            Route::delete('/groups/{group}/members/{student}', [GroupController::class, 'removeMember']);
            Route::post('/groups/{group}/sessions/generate', [GroupController::class, 'generateSessions']);
        });

        // Hisobotlar
        Route::middleware('perm:report.view')->group(function () {
            Route::get('/reports/overview', [ReportController::class, 'overview']);
            Route::get('/reports/attendance', [ReportController::class, 'attendance']);
            Route::get('/reports/attendance.csv', [ReportController::class, 'attendanceCsv']);
            Route::get('/reports/quizzes', [ReportController::class, 'quizzes']);
        });

        // Testlar
        Route::middleware('perm:course.view')->group(function () {
            Route::get('/courses/{course}/quizzes', [QuizController::class, 'index']);
            Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
        });
        Route::middleware('perm:quiz.manage')->group(function () {
            Route::post('/courses/{course}/quizzes', [QuizController::class, 'store']);
            Route::patch('/quizzes/{quiz}', [QuizController::class, 'update']);
            Route::delete('/quizzes/{quiz}', [QuizController::class, 'destroy']);
            Route::post('/quizzes/{quiz}/questions', [QuizController::class, 'storeQuestion']);
            Route::patch('/questions/{question}', [QuizController::class, 'updateQuestion']);
            Route::delete('/questions/{question}', [QuizController::class, 'destroyQuestion']);
            Route::get('/quizzes/{quiz}/results', [QuizController::class, 'results']);
        });
        Route::middleware('perm:quiz.take')->group(function () {
            Route::post('/quizzes/{quiz}/attempts', [QuizController::class, 'start']);
            Route::post('/attempts/{attempt}/submit', [QuizController::class, 'submit']);
        });

        // Sertifikatlar
        Route::get('/certificates', [CertificateController::class, 'index']);
        Route::middleware('perm:group.manage')->group(function () {
            Route::get('/groups/{group}/certificates', [CertificateController::class, 'preview']);
            Route::post('/groups/{group}/certificates', [CertificateController::class, 'issue']);
            Route::delete('/certificates/{code}', [CertificateController::class, 'revoke']);
        });

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
