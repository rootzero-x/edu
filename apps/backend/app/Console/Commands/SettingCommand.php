<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan tisu:setting telegram.admin_chat_ids '[6817322973]'
 * php artisan tisu:setting telegram.admin_chat_ids          — joriy qiymat
 * php artisan tisu:setting telegram.admin_chat_ids --reset  — default'ga qaytarish
 */
class SettingCommand extends Command
{
    protected $signature = 'tisu:setting {key : Sozlama kaliti} {value? : Yangi qiymat (JSON)} {--reset : Default qiymatga qaytarish}';

    protected $description = "Sozlamani ko'rish yoki o'rnatish (Settings::set validatsiyasi bilan)";

    public function handle(Settings $settings): int
    {
        $key = (string) $this->argument('key');
        if (! isset(Settings::definitions()[$key])) {
            $this->error("Noma'lum sozlama: $key");

            return self::FAILURE;
        }
        if ($this->option('reset')) {
            $settings->reset($key);
            $this->info("$key default qiymatga qaytarildi.");
        } elseif (($raw = $this->argument('value')) !== null) {
            try {
                $value = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->error("Qiymat to'g'ri JSON bo'lishi kerak (satr uchun: '\"matn\"'). ".$e->getMessage());

                return self::FAILURE;
            }
            try {
                $settings->set([$key => $value], null);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->error("$field: ".implode(' ', $messages));
                }

                return self::FAILURE;
            }
            $this->info("$key saqlandi.");
        }
        $this->line(json_encode($settings->get($key), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
