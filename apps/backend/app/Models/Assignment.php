<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Uyga vazifa (guruhga, ixtiyoriy ravishda mashg'ulot yoki kurs darsiga bog'langan). */
class Assignment extends Model
{
    protected $fillable = ['group_id', 'session_id', 'lesson_id', 'title', 'body', 'due_at', 'max_score', 'created_by', 'summary_sent_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'summary_sent_at' => 'datetime', 'max_score' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
