<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HomeworkController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\MarksController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoomController;
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
    Route::get('/public/announcements', [AnnouncementController::class, 'public'])->middleware('throttle:api');
    Route::get('/public/settings', [SettingsController::class, 'public'])->middleware('throttle:api');
    Route::get('/public/courses', [LandingController::class, 'courses'])->middleware('throttle:api');
    // Yuklangan fayl: imzolangan muddatli havola (token shart emas — havolani faqat ruxsati bor foydalanuvchi oladi).
    Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download')
        ->middleware(['signed:relative', 'throttle:api'])->whereNumber('file');

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
        });
        Route::middleware('perm:user.manage')->group(function () {
            Route::get('/users/{parent}/children', [UserController::class, 'children']);
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
            Route::get('/groups/teachers', [GroupController::class, 'teachers']);
            Route::post('/groups', [GroupController::class, 'store']);
            Route::patch('/groups/{group}', [GroupController::class, 'update']);
            Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
            Route::delete('/groups/{group}/members/{student}', [GroupController::class, 'removeMember']);
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

        // Hisobotlar
        Route::middleware('perm:report.view')->group(function () {
            Route::get('/reports/overview', [ReportController::class, 'overview']);
            Route::get('/reports/attendance', [ReportController::class, 'attendance']);
            Route::get('/reports/attendance.csv', [ReportController::class, 'attendanceCsv']);
            Route::get('/reports/quizzes', [ReportController::class, 'quizzes']);
        });

        // To'lovlar: o'quvchi — o'zi, ota-ona — farzandlari (tekshiruv controller'da); boshqarish — billing.manage
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

        // ── Sprint 3 ─────────────────────────────────────────
        // Taqvim va xonalar
        Route::get('/calendar', [CalendarController::class, 'index']);
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::middleware('perm:group.manage')->group(function () {
            Route::post('/rooms', [RoomController::class, 'store']);
            Route::patch('/rooms/{room}', [RoomController::class, 'update']);
            Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
        });

        // Uyga vazifa va baholar (ruxsatlar controller'da: guruh o'qituvchisi / group.manage / guruh a'zosi)
        Route::get('/assignments', [HomeworkController::class, 'index']);
        Route::get('/assignments/{assignment}', [HomeworkController::class, 'show'])->whereNumber('assignment');
        Route::post('/groups/{group}/assignments', [HomeworkController::class, 'store'])->whereNumber('group');
        Route::patch('/assignments/{assignment}', [HomeworkController::class, 'update']);
        Route::delete('/assignments/{assignment}', [HomeworkController::class, 'destroy']);
        Route::post('/assignments/{assignment}/files', [HomeworkController::class, 'uploadFile']);
        Route::put('/assignments/{assignment}/grades', [HomeworkController::class, 'grade']);
        Route::put('/assignments/{assignment}/submission', [HomeworkController::class, 'submit']);
        Route::post('/assignments/{assignment}/submission/files', [HomeworkController::class, 'uploadSubmissionFile']);
        Route::get('/marks/me', [HomeworkController::class, 'myMarks']);
        Route::get('/sessions/{session}/marks', [MarksController::class, 'show']);
        Route::put('/sessions/{session}/marks', [MarksController::class, 'update'])->middleware('perm:attendance.mark');

        // Fayllar
        Route::get('/files/{file}/link', [FileController::class, 'link'])->whereNumber('file');
        Route::delete('/files/{file}', [FileController::class, 'destroy'])->whereNumber('file');
        Route::get('/files/usage', [FileController::class, 'usage'])->middleware('perm:settings.manage');
        Route::post('/lessons/{lesson}/materials/upload', [FileController::class, 'uploadMaterial'])->middleware('perm:course.manage');

        // Bildirishnomalar markazi; yetkazish hisoboti — foydalanuvchilarni boshqaruvchiga
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread', [NotificationController::class, 'unread']);
        Route::post('/notifications/read', [NotificationController::class, 'markRead']);
        Route::middleware('perm:user.manage')->group(function () {
            Route::get('/notifications/report', [NotificationController::class, 'report']);
            Route::get('/notifications/unreachable', [NotificationController::class, 'unreachable']);
            Route::get('/notifications/unreachable.csv', [NotificationController::class, 'unreachableCsv']);
        });

        // Audit jurnali (faqat administratorlar) va sozlama tarixi
        Route::middleware('perm:audit.view')->group(function () {
            Route::get('/audit', [AuditController::class, 'index']);
            Route::get('/audit/facets', [AuditController::class, 'facets']);
        });
        Route::get('/settings/{key}/history', [AuditController::class, 'settingHistory'])->middleware('perm:settings.manage');

        // Ko'rsatkichlar (KPI) va uyga vazifa hisoboti
        Route::middleware('perm:report.view')->group(function () {
            Route::get('/reports/kpi', [KpiController::class, 'kpi']);
            Route::get('/reports/kpi/students', [KpiController::class, 'students']);
            Route::get('/reports/homework', [KpiController::class, 'homework']);
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
