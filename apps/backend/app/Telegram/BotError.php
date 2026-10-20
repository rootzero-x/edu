<?php

namespace App\Telegram;

use RuntimeException;

/** Foydalanuvchiga ko'rsatiladigan xato (API'ning abort/validatsiya xabari). */
final class BotError extends RuntimeException {}
