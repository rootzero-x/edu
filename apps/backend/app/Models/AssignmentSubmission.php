<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O'quvchining vazifa javobi va bahosi. submitted_at bo'sh — hali topshirilmagan (qoralama yoki faqat baho). */
class AssignmentSubmission extends Model
{
    protected $fillable = ['assignment_id', 'student_id', 'body', 'link_url', 'submitted_at', 'score', 'feedback', 'graded_by', 'graded_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'graded_at' => 'datetime', 'score' => 'integer'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
