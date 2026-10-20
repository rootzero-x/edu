<?php

/*
 * Zaxira sozlamalari (.env). Biznes sozlamalari (yoqish, muddat, Telegram'ga yuborish) — config/tisu.php → backup.*.
 * Shifrlash kaliti repo'da HECH QACHON saqlanmaydi: faqat serverdagi .env da va ishonchli joyda (parol menejeri).
 */
return [
    // php artisan tisu:backup-key — yangi kalit chiqaradi (base64:...). Kalitsiz zaxira shifrlanmaydi va tashqariga yuborilmaydi.
    'key' => env('BACKUP_ENCRYPTION_KEY', ''),

    // Lokal zaxiralar papkasi (o'sha serverda!). Standart: ~/tisu-backups
    'dir' => env('BACKUP_DIR') ?: (getenv('HOME') ?: dirname(__DIR__, 3)).'/tisu-backups',
];
