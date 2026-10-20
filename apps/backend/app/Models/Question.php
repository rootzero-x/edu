<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = ['quiz_id', 'type', 'text', 'points', 'position', 'accepted_answers', 'replaces_id'];

    protected function casts(): array
    {
        return ['accepted_answers' => 'array', 'points' => 'integer', 'archived_at' => 'datetime'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /** QuizGrader formati: correct — to'g'ri variant id'lari yoki qabul qilinadigan matnlar. */
    public function forGrader(): array
    {
        return [
            'type' => $this->type,
            'points' => $this->points,
            'correct' => $this->type === 'TEXT'
                ? array_values((array) $this->accepted_answers)
                : $this->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        ];
    }
}
