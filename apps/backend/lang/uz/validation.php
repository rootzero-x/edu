<?php

// Standart (boshlang'ich) o'zbekcha xabarlar. Har qanday xabarni admin paneldan
// "i18n.overrides" sozlamasi orqali qayta yozish mumkin (masalan "validation.required").
return [
    'accepted' => ':Attribute qabul qilinishi kerak.',
    'after' => ':Attribute :date dan keyin bo\'lishi kerak.',
    'after_or_equal' => ':Attribute :date yoki undan keyin bo\'lishi kerak.',
    'array' => ':Attribute ro\'yxat bo\'lishi kerak.',
    'before' => ':Attribute :date dan oldin bo\'lishi kerak.',
    'between' => [
        'numeric' => ':Attribute :min va :max oralig\'ida bo\'lishi kerak.',
        'string' => ':Attribute :min dan :max gacha belgidan iborat bo\'lishi kerak.',
        'array' => ':Attribute :min dan :max gacha elementdan iborat bo\'lishi kerak.',
    ],
    'boolean' => ':Attribute ha yoki yo\'q bo\'lishi kerak.',
    'date' => ':Attribute to\'g\'ri sana emas.',
    'date_format' => ':Attribute :format formatida bo\'lishi kerak.',
    'different' => ':Attribute va :other har xil bo\'lishi kerak.',
    'email' => ':Attribute to\'g\'ri email bo\'lishi kerak.',
    'exists' => 'Tanlangan :attribute topilmadi.',
    'in' => 'Tanlangan :attribute noto\'g\'ri.',
    'integer' => ':Attribute butun son bo\'lishi kerak.',
    'max' => [
        'numeric' => ':Attribute :max dan katta bo\'lmasligi kerak.',
        'string' => ':Attribute :max belgidan oshmasligi kerak.',
        'array' => ':Attribute :max elementdan oshmasligi kerak.',
        'file' => ':Attribute :max KB dan oshmasligi kerak.',
    ],
    'min' => [
        'numeric' => ':Attribute kamida :min bo\'lishi kerak.',
        'string' => ':Attribute kamida :min belgidan iborat bo\'lishi kerak.',
        'array' => ':Attribute kamida :min elementdan iborat bo\'lishi kerak.',
        'file' => ':Attribute kamida :min KB bo\'lishi kerak.',
    ],
    'numeric' => ':Attribute son bo\'lishi kerak.',
    'present' => ':Attribute maydoni bo\'lishi kerak.',
    'prohibited' => ':Attribute maydonini o\'zgartirib bo\'lmaydi.',
    'regex' => ':Attribute formati noto\'g\'ri.',
    'required' => ':Attribute kiritilishi shart.',
    'required_if' => ':other :value bo\'lganda :attribute kiritilishi shart.',
    'size' => [
        'numeric' => ':Attribute :size bo\'lishi kerak.',
        'string' => ':Attribute :size belgidan iborat bo\'lishi kerak.',
        'array' => ':Attribute :size elementdan iborat bo\'lishi kerak.',
    ],
    'string' => ':Attribute matn bo\'lishi kerak.',
    'unique' => 'Bu :attribute allaqachon mavjud.',
    'url' => ':Attribute to\'g\'ri havola (URL) bo\'lishi kerak.',

    'attributes' => [
        'phone' => 'telefon raqami', 'password' => 'parol', 'current_password' => 'joriy parol', 'new_password' => 'yangi parol',
        'first_name' => 'ism', 'last_name' => 'familiya', 'role' => 'rol', 'branch_id' => 'filial',
        'title' => 'nomi', 'name' => 'nomi', 'description' => 'tavsif', 'cover_url' => 'muqova rasmi', 'duration_months' => 'davomiylik',
        'url' => 'havola', 'content' => 'matn', 'type' => 'turi', 'position' => 'tartib raqami', 'text' => 'matn', 'points' => 'ball',
        'options' => 'variantlar', 'accepted_answers' => 'qabul qilinadigan javoblar',
        'course_id' => 'kurs', 'teacher_id' => "o'qituvchi", 'student_id' => "o'quvchi",
        'start_date' => 'boshlanish sanasi', 'end_date' => 'tugash sanasi', 'room' => 'xona', 'schedules' => 'jadval',
        'schedules.*.weekday' => 'hafta kuni', 'schedules.*.start_time' => 'boshlanish vaqti', 'schedules.*.end_time' => 'tugash vaqti',
        'items' => "ro'yxat", 'items.*.status' => 'holat', 'items.*.arrived_at' => 'kelgan vaqti', 'topic' => 'mavzu',
        'pass_percent' => "o'tish bali", 'max_attempts' => 'urinishlar soni', 'time_limit_sec' => 'vaqt chegarasi',
        'from' => 'boshlanish sanasi', 'to' => 'tugash sanasi', 'values' => 'qiymatlar', 'address' => 'manzil',
    ],
];
