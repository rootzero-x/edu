<?php

/*
 * Sozlamalar ro'yxati. Bu yerda faqat BOSHLANG'ICH qiymatlar turadi —
 * haqiqiy qiymatlar `settings` jadvalida saqlanadi va admin paneldan
 * (PATCH /v1/settings) o'zgartiriladi. Kod hech qachon qiymatni bevosita
 * shu fayldan o'qimaydi, faqat App\Support\Settings orqali.
 *
 * type: int | bool | string | text | list | object
 * public: true bo'lsa, login qilmasdan GET /v1/public/settings orqali ochiq.
 */

$allPermissions = [
    'course.manage', 'course.view', 'group.manage', 'attendance.mark', 'quiz.manage', 'quiz.take',
    'payment.manage', 'payment.view', 'report.view', 'user.manage', 'settings.manage',
];

return [
    'permissions' => $allPermissions,

    'settings' => [
        // ── Sayt (ochiq) ──────────────────────────────────────
        'site.name' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Sayt nomi', 'default' => 'TISU Edu'],
        'site.tagline' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Qisqa tavsif', 'default' => "O'quv markazi platformasi"],
        'site.description' => ['group' => 'site', 'type' => 'text', 'public' => true, 'label' => 'SEO tavsif', 'default' => "TISU o'quv platformasi: kurslar, testlar, guruhlar va davomat"],
        'site.logo_url' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Logo URL', 'default' => ''],
        'site.primary_color' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Asosiy rang (#hex)', 'default' => '#2563eb'],
        'site.contact_phone' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Aloqa telefoni', 'default' => ''],
        'site.contact_telegram' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Telegram (@username)', 'default' => ''],
        'site.address' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Manzil', 'default' => ''],
        'site.features' => ['group' => 'site', 'type' => 'list', 'public' => true, 'label' => "Bosh sahifa bloklari [{title,text}]", 'default' => [
            ['title' => 'Kurslar va darslar', 'text' => "Video, fayl va matnli darslar, modullar bo'yicha."],
            ['title' => 'Testlar', 'text' => 'Vaqt cheklovi, urinishlar soni, avtomatik baholash.'],
            ['title' => 'Guruhlar va davomat', 'text' => "Jadval, davomat, ota-onaga xabar."],
            ['title' => "To'lovlar", 'text' => "Oylik to'lov, chegirma, qarzdorlik hisobi."],
            ['title' => 'Sertifikat', 'text' => "Kursni tugatganlarga QR-kodli sertifikat."],
            ['title' => 'Telegram', 'text' => 'Bot va Mini App orqali kirish.'],
        ]],
        'site.default_locale' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Standart til (uz/ru/en)', 'default' => 'uz'],
        'site.currency' => ['group' => 'site', 'type' => 'string', 'public' => true, 'label' => 'Valyuta belgisi', 'default' => "so'm"],

        // ── Davomat ──────────────────────────────────────────
        'attendance.grace_minutes' => ['group' => 'attendance', 'type' => 'int', 'min' => 0, 'max' => 120, 'label' => "Kechikish hisoblanmaydigan daqiqalar", 'default' => 5],
        'attendance.absent_after_minutes' => ['group' => 'attendance', 'type' => 'int', 'min' => 1, 'max' => 240, 'label' => "Shundan ko'p kechiksa — kelmagan", 'default' => 30],
        'attendance.notify_parents' => ['group' => 'attendance', 'type' => 'bool', 'label' => "Kelmaganda ota-onaga xabar", 'default' => true],

        'attendance.teacher_edit_days' => ['group' => 'attendance', 'type' => 'int', 'min' => 0, 'max' => 365, 'label' => "O'qituvchi davomatni necha kun ichida o'zgartira oladi", 'default' => 3],
        'attendance.sessions_ahead_days' => ['group' => 'attendance', 'type' => 'int', 'min' => 7, 'max' => 366, 'label' => 'Jadvaldan oldindan yaratiladigan darslar (kun)', 'default' => 31],
        'attendance.notify_statuses' => ['group' => 'attendance', 'type' => 'list', 'label' => "Ota-onaga xabar boradigan holatlar", 'default' => ['ABSENT']],
        'telegram.text.attendance_parent' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: ota-onaga davomat xabari', 'default' => "{child} bugun {date} {time} dagi «{group}» darsida: {status}."],
        'attendance.status_labels' => ['group' => 'attendance', 'type' => 'object', 'public' => true, 'label' => 'Davomat holatlari nomlari', 'default' => [
            'PRESENT' => 'keldi', 'LATE' => 'kechikdi', 'ABSENT' => 'kelmadi', 'EXCUSED' => 'sababli',
        ]],

        // ── Testlar ──────────────────────────────────────────
        'quiz.default_pass_percent' => ['group' => 'quiz', 'type' => 'int', 'min' => 1, 'max' => 100, 'label' => "Yangi test uchun o'tish foizi", 'default' => 60],
        'quiz.default_max_attempts' => ['group' => 'quiz', 'type' => 'int', 'min' => 1, 'max' => 100, 'label' => 'Yangi test uchun urinishlar soni', 'default' => 3],
        'quiz.show_correct_answers' => ['group' => 'quiz', 'type' => 'bool', 'label' => "Topshirgandan keyin to'g'ri javoblarni ko'rsatish", 'default' => true],
        'quiz.submit_grace_seconds' => ['group' => 'quiz', 'type' => 'int', 'min' => 0, 'max' => 600, 'label' => "Vaqt tugagach qo'shimcha soniyalar", 'default' => 15],

        // ── Sertifikat ───────────────────────────────────────
        'certificate.code_prefix' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => 'Sertifikat raqami prefiksi', 'default' => 'TISU'],
        'certificate.title' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => 'Sertifikat sarlavhasi', 'default' => 'SERTIFIKAT'],
        'certificate.body' => ['group' => 'certificate', 'type' => 'text', 'public' => true, 'label' => 'Sertifikat matni ({name}, {course}, {date}, {hours})', 'default' => "{name}\n«{course}» kursini muvaffaqiyatli tamomlagani uchun berildi."],
        'certificate.signer_name' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => 'Imzolovchi F.I.Sh.', 'default' => ''],
        'certificate.signer_title' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => 'Imzolovchi lavozimi', 'default' => 'Direktor'],
        'certificate.background_url' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => 'Sertifikat fon rasmi URL (A4 landshaft)', 'default' => ''],
        'telegram.text.certificate_issued' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: sertifikat berildi', 'default' => "🎓 Tabriklaymiz, {name}! «{course}» kursi bo'yicha sertifikat berildi: {url}"],
        'certificate.public_base_url' => ['group' => 'certificate', 'type' => 'string', 'public' => true, 'label' => "Sertifikatni tekshirish sahifasi manzili", 'default' => 'https://tisu.uzstudents.uz/c/'],
        'certificate.min_attendance_percent' => ['group' => 'certificate', 'type' => 'int', 'min' => 0, 'max' => 100, 'label' => 'Minimal davomat (%)', 'default' => 75],
        'certificate.require_final_quiz' => ['group' => 'certificate', 'type' => 'bool', 'label' => "Yakuniy testdan o'tish shart", 'default' => true],
        'certificate.require_no_debt' => ['group' => 'certificate', 'type' => 'bool', 'label' => "Qarzdorlik bo'lmasligi shart", 'default' => true],

        // ── Moliya ───────────────────────────────────────────
        'billing.invoice_day' => ['group' => 'billing', 'type' => 'int', 'min' => 1, 'max' => 28, 'label' => "Oylik hisob chiqariladigan kun", 'default' => 1],
        'billing.prorate_first_month' => ['group' => 'billing', 'type' => 'bool', 'label' => "Birinchi oy proporsional hisoblansin", 'default' => true],

        // ── Xavfsizlik ───────────────────────────────────────
        'auth.token_ttl_days' => ['group' => 'security', 'type' => 'int', 'min' => 1, 'max' => 365, 'label' => 'Kirish tokeni muddati (kun)', 'default' => 30],
        'auth.min_password_length' => ['group' => 'security', 'type' => 'int', 'min' => 8, 'max' => 64, 'label' => 'Parol minimal uzunligi', 'default' => 10],
        'security.login_per_minute' => ['group' => 'security', 'type' => 'int', 'min' => 1, 'max' => 100, 'label' => 'Login urinishlari / daqiqa', 'default' => 10],
        'security.api_per_minute' => ['group' => 'security', 'type' => 'int', 'min' => 10, 'max' => 10000, 'label' => "API so'rovlari / daqiqa", 'default' => 120],

        // ── Telegram ─────────────────────────────────────────
        'telegram.bot_username' => ['group' => 'telegram', 'type' => 'string', 'public' => true, 'label' => 'Bot username (@siz)', 'default' => ''],
        'telegram.self_registration' => ['group' => 'telegram', 'type' => 'bool', 'label' => "Bot orqali yangi o'quvchi ro'yxatdan o'tishi", 'default' => true],
        'telegram.login_code_ttl_seconds' => ['group' => 'telegram', 'type' => 'int', 'min' => 60, 'max' => 3600, 'label' => 'Kirish havolasi amal qilish muddati (soniya)', 'default' => 300],
        'telegram.miniapp_auth_max_age_seconds' => ['group' => 'telegram', 'type' => 'int', 'min' => 60, 'max' => 86400, 'label' => 'Mini App initData amal qilish muddati (soniya)', 'default' => 3600],
        'telegram.text.welcome' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: salomlashish', 'default' => "Assalomu alaykum! {site} botiga xush kelibsiz.\nDavom etish uchun telefon raqamingizni yuboring."],
        'telegram.text.share_phone_button' => ['group' => 'telegram', 'type' => 'string', 'label' => 'Bot: telefon tugmasi', 'default' => '📱 Telefon raqamni yuborish'],
        'telegram.text.registered' => ['group' => 'telegram', 'type' => 'text', 'label' => "Bot: ro'yxatdan o'tdi", 'default' => "Rahmat, {name}! Siz ro'yxatdan o'tdingiz."],
        'telegram.text.linked' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: akkaunt bog\'landi', 'default' => "Rahmat, {name}! Telegram akkauntingiz bog'landi."],
        'telegram.text.registration_closed' => ['group' => 'telegram', 'type' => 'text', 'label' => "Bot: ro'yxat yopiq", 'default' => "Bu raqam tizimda topilmadi. Ro'yxatdan o'tish uchun administratorga murojaat qiling."],
        'telegram.text.not_own_contact' => ['group' => 'telegram', 'type' => 'text', 'label' => "Bot: begona kontakt", 'default' => "Iltimos, pastdagi tugma orqali o'z raqamingizni yuboring."],
        'telegram.text.login_confirmed' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: saytga kirish tasdiqlandi', 'default' => "✅ Saytga kirish tasdiqlandi. Brauzerga qayting."],
        'telegram.text.login_expired' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: havola eskirgan', 'default' => "Havola eskirgan. Saytda qaytadan \"Telegram orqali kirish\" ni bosing."],
        'telegram.text.account_blocked' => ['group' => 'telegram', 'type' => 'text', 'label' => 'Bot: akkaunt bloklangan', 'default' => "Akkauntingiz faol emas. Administratorga murojaat qiling."],

        // ── Interfeys ────────────────────────────────────────
        'ui.page_size' => ['group' => 'ui', 'type' => 'int', 'min' => 5, 'max' => 500, 'label' => 'Jadvallarda qatorlar soni', 'default' => 50],

        // ── Rollar ───────────────────────────────────────────
        // SUPER_ADMIN har doim hamma huquqqa ega (o'zini qulflab qo'ymaslik uchun).
        'roles.permissions' => ['group' => 'roles', 'type' => 'object', 'label' => 'Rollar huquqlari', 'default' => [
            'ADMIN' => ['course.manage', 'course.view', 'group.manage', 'attendance.mark', 'quiz.manage',
                'payment.manage', 'payment.view', 'report.view', 'user.manage'],
            'TEACHER' => ['course.manage', 'course.view', 'attendance.mark', 'quiz.manage', 'report.view'],
            'STUDENT' => ['course.view', 'quiz.take', 'payment.view'],
            'PARENT' => ['course.view', 'payment.view'],
        ]],
    ],
];
