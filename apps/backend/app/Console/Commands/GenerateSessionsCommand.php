<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSessionsCommand extends Command
{
    protected $signature = 'tisu:generate-sessions';

    protected $description = "Faol guruhlar uchun jadvaldan darslarni oldindan yaratish";

    public function handle(Settings $settings, SessionGenerator $generator): int
    {
        if (! $settings->bool('automation.generate_sessions')) {
            $this->line("O'chirilgan (automation.generate_sessions).");

            return self::SUCCESS;
        }
        $total = 0;
        StudyGroup::where('active', true)->with('schedules')->each(function (StudyGroup $g) use ($generator, &$total) {
            $total += $generator->generate($g)['created'];
        });
        $this->info("Yaratildi: $total ta dars.");

        return self::SUCCESS;
    }
}
