<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = ['course_id', 'lesson_id', 'title', 'is_final', 'pass_percent', 'max_attempts', 'time_limit_sec', 'shuffle', 'published'];

    protected function casts(): array
    {
        return ['is_final' => 'boolean', 'shuffle' => 'boolean', 'published' => 'boolean',
            'pass_percent' => 'integer', 'max_attempts' => 'integer', 'time_limit_sec' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Amaldagi savollar (arxivlangan eski versiyalar — topshirilgan urinishlar tarixi uchun saqlanadi, ko'rinmaydi). */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->whereNull('archived_at')->orderBy('position');
    }
}
