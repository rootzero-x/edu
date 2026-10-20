<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['quiz_id', 'student_id', 'started_at', 'submitted_at', 'answers', 'score', 'max_score', 'percent', 'passed'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'answers' => 'array', 'passed' => 'boolean',
            'percent' => 'float', 'score' => 'integer', 'max_score' => 'integer'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
