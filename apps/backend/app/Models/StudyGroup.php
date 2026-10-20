<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyGroup extends Model
{
    protected $table = 'study_groups';

    protected $fillable = ['name', 'course_id', 'branch_id', 'teacher_id', 'start_date', 'end_date', 'room', 'active'];

    protected static function booted(): void
    {
        // Filial tanlanmaydi: har bir guruh yagona filialga yoziladi.
        static::creating(function (StudyGroup $g) {
            $g->branch_id ??= Branch::primaryId();
        });
    }

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'active' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** Hozirgi a'zolar (chiqib ketmaganlar). */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members', 'group_id', 'student_id')
            ->withPivot(['joined_at', 'left_at', 'discount_id'])->wherePivotNull('left_at');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(GroupSchedule::class, 'group_id')->orderBy('weekday')->orderBy('start_time');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'group_id')->orderBy('starts_at');
    }
}
