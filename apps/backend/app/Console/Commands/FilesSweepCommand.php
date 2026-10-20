<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use App\Support\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Egasiz qolgan fayllarni tozalash: material/vazifa/javob o'chirilgan (masalan, kurs yoki dars bilan birga),
 * lekin fayl qolgan bo'lsa. Yuklash jarayonida egasi hali yozilmagan fayllarga 1 kun vaqt beriladi.
 */
class FilesSweepCommand extends Command
{
    protected $signature = 'tisu:files-sweep {--dry-run : Faqat ro\'yxat}';

    protected $description = "Egasiz qolgan yuklangan fayllarni o'chirish";

    public function handle(Uploads $uploads): int
    {
        $owners = ['material' => 'lesson_materials', 'assignment' => 'assignments', 'submission' => 'assignment_submissions'];
        $orphans = StoredFile::query()->where('created_at', '<', now()->subDay())
            ->where(function ($w) use ($owners) {
                $w->whereNull('owner_id')->orWhereNotIn('owner_type', array_keys($owners));
                foreach ($owners as $type => $table) {
                    $w->orWhere(fn ($q) => $q->where('owner_type', $type)->whereNotExists(fn ($e) => $e->from($table)->whereColumn("$table.id", 'files.owner_id')));
                }
            })
            // Material faylining o'zi materialga bog'langan bo'lishi shart (file_id).
            ->orWhere(fn ($q) => $q->where('owner_type', 'material')->where('created_at', '<', now()->subDay())
                ->whereNotExists(fn ($e) => $e->from('lesson_materials')->whereColumn('lesson_materials.file_id', 'files.id')))
            ->get();
        foreach ($orphans as $f) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '')."#{$f->id} {$f->owner_type}:{$f->owner_id} {$f->original_name}");
            if (! $this->option('dry-run')) {
                $uploads->delete($f);
            }
        }
        // Diskda bo'lib, bazada yozuvi yo'q fayllar (masalan, yarim qolgan yuklash).
        $known = DB::table('files')->pluck('path')->flip();
        $stray = 0;
        foreach (Storage::disk(Uploads::DISK)->allFiles() as $path) {
            if (! isset($known[$path]) && Storage::disk(Uploads::DISK)->lastModified($path) < now()->subDay()->getTimestamp()) {
                $stray++;
                if (! $this->option('dry-run')) {
                    Storage::disk(Uploads::DISK)->delete($path);
                }
            }
        }
        $this->info("Egasiz fayllar: {$orphans->count()}, bazasiz fayllar: $stray");

        return self::SUCCESS;
    }
}
