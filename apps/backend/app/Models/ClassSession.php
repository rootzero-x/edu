<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    protected $table = 'class_sessions';

    protected $fillable = ['group_id', 'starts_at', 'ends_at', 'topic', 'cancelled'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(\App\Models\AttendanceRecord::class, 'session_id');
    }
}
