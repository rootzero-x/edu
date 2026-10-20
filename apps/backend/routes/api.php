<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicLeadController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SystemStatusController;
use App\Http\Controllers\Api\TelegramAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/telegram/start', [TelegramAuthController::class, 'start'])->middleware('throttle:login');
    Route::post('/auth/telegram/poll', [TelegramAuthController::class, 'poll'])->middleware('throttle:api');
    Route::post('/auth/telegram/miniapp', [TelegramAuthController::class, 'miniApp'])->middleware('throttle:miniapp');
    Route::post('/telegram/webhook', [TelegramAuthController::class, 'webhook']);
    Route::get('/public/certificates/{code}', [CertificateController::class, 'verify'])->middleware('throttle:api');
    Route::get('/public/announcements', [AnnouncementController::class, 'public'])->middleware('throttle:api');
    Route::get('/public/settings', [SettingsController::class, 'public'])->middleware('throttle:api');
    Route::get('/public/courses', [LandingController::class, 'courses'])->middleware('throttle:api');
    Route::post('/public/leads', [PublicLeadController::class, 'store'])->middleware('throttle:leads');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/password', [AuthController::class, 'changePassword']);
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/search', SearchController::class);
        Route::get('/users/{user}/profile', [ProfileController::class, 'show'])->whereNumber('user');

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
            Route::get('/users/duplicates', [UserController::class, 'duplicates']);
            Route::patch('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
            Route::post('/users/{user}/approve', [UserController::class, 'approve'])->whereNumber('user');
            Route::post('/users/{user}/reject', [UserController::class, 'reject'])->whereNumber('user');
            Route::post('/users/{user}/merge', [UserController::class, 'merge'])->whereNumber('user');
            // Tizim holati: cron, vazifalar, zaxira, Telegram navbati.
            Route::get('/system/status', SystemStatusController::class);
        });
        Route::middleware('perm:user.manage')->group(function () {
            Route::get('/imports/students/template', [ImportController::class, 'template']);
            Route::post('/imports/students/preview', [ImportController::class, 'preview']);
            Route::post('/imports/students/commit', [ImportController::class, 'commit']);
        });

        // Guruhlar va davomat (ko'rish — ko'rinish doirasi controller'da)
        Route::get('/groups', [GroupController::class, 'index']);
        Route::get('/groups/{group}', [GroupController::class, 'show'])->whereNumber('group');
        Route::get('/groups/{group}/sessions', [GroupController::class, 'sessions'])->whereNumber('group');
        Route::get('/groups/{group}/members', [GroupController::class, 'members'])->whereNumber('group');
        Route::get('/groups/{group}/members/{student}/history', [GroupController::class, 'memberHistory'])->whereNumber('group');
        Route::get('/groups/{group}/attendance', [AttendanceController::class, 'report'])->whereNumber('group');
        Route::get('/attendance/reports', [AttendanceController::class, 'reports']);
        Route::get('/sessions', [GroupController::class, 'allSessions']);
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
            Route::get('/groups/teachers', [GroupController::class, 'teachers']);
            Route::post('/groups', [GroupController::class, 'store']);
            Route::patch('/groups/{group}', [GroupController::class, 'update']);
            Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
            Route::delete('/groups/{group}/members/{student}', [GroupController::class, 'removeMember']);
            Route::post('/groups/{group}/members/bulk', [GroupController::class, 'addMembers']);
            Route::post('/groups/{group}/members/{student}/leave', [GroupController::class, 'removeMember']);
            Route::post('/groups/{group}/members/{student}/freeze', [GroupController::class, 'freezeMember']);
            Route::post('/groups/{group}/members/{student}/unfreeze', [GroupController::class, 'unfreezeMember']);
            Route::post('/groups/{group}/members/{student}/transfer', [GroupController::class, 'transferMember']);
            Route::post('/groups/{group}/members/{student}/graduate', [GroupController::class, 'graduateMember']);
            Route::post('/groups/{group}/graduate', [GroupController::class, 'graduateAll']);
            Route::post('/groups/{group}/sessions/generate', [GroupController::class, 'generateSessions']);
        });

        // Yangiliklar va e'lonlar
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/announcements/unread', [AnnouncementController::class, 'unread']);
        Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'markRead'])->whereNumber('announcement');
        Route::middleware('perm:announcement.manage')->group(function () {
            Route::post('/announcements', [AnnouncementController::class, 'store']);
            Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
            Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);
            Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
        });

        // Arizalar (CRM)
        Route::middleware('perm:lead.manage')->group(function () {
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::get('/leads/stats', [LeadController::class, 'stats']);
            Route::get('/leads/options', [LeadController::class, 'owners']);
            Route::get('/leads/trial-sessions', [LeadController::class, 'trialSessions']);
            Route::get('/leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead');
            Route::patch('/leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead');
            Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead');
            Route::post('/leads/{lead}/status', [LeadController::class, 'status'])->whereNumber('lead');
            Route::post('/leads/{lead}/activities', [LeadController::class, 'addActivity'])->whereNumber('lead');
            Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead');
        });

        // Hisobotlar
        Route::middleware('perm:report.view')->group(function () {
            Route::get('/reports/overview', [ReportController::class, 'overview']);
            Route::get('/reports/attendance', [ReportController::class, 'attendance']);
            Route::get('/reports/attendance.csv', [ReportController::class, 'attendanceCsv']);
            Route::get('/reports/quizzes', [ReportController::class, 'quizzes']);
        });

        // To'lovlar: o'quvchi — o'zi (tekshiruv controller'da); boshqarish — billing.manage
        Route::get('/billing/me', [BillingController::class, 'me']);
        Route::get('/billing/students/{student}', [BillingController::class, 'show'])->whereNumber('student');
        Route::middleware('perm:billing.manage')->group(function () {
            Route::get('/billing/balances', [BillingController::class, 'balances']);
            Route::get('/billing/balances.csv', [BillingController::class, 'balancesCsv']);
            Route::get('/billing/report', [BillingController::class, 'report']);
            Route::get('/billing/entries.csv', [BillingController::class, 'entriesCsv']);
            Route::post('/billing/students/{student}/payments', [BillingController::class, 'storePayment'])->whereNumber('student');
            Route::post('/billing/students/{student}/adjustments', [BillingController::class, 'storeAdjustment'])->whereNumber('student');
            Route::post('/billing/entries/{entry}/void', [BillingController::class, 'void'])->whereNumber('entry');
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

        // Kurslar (boshqarish): course.manage — hamma kurslar, course.author — faqat o'zi yaratganlari (CourseController::authorizeEdit)
        Route::middleware('perm:course.manage|course.author')->group(function () {
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
