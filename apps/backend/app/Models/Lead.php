<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ariza (lid): sayt formasi, bot yoki xodim kiritgan murojaat. Holatlar: NEW → CONTACTED → TRIAL → ENROLLED / LOST. */
class Lead extends Model
{
    public const NEW = 'NEW';

    public const CONTACTED = 'CONTACTED';

    public const TRIAL = 'TRIAL';

    public const ENROLLED = 'ENROLLED';

    public const LOST = 'LOST';

    public const STATUSES = [self::NEW, self::CONTACTED, self::TRIAL, self::ENROLLED, self::LOST];

    /** Hali yopilmagan (ish olib borilayotgan) holatlar. */
    public const OPEN = [self::NEW, self::CONTACTED, self::TRIAL];

    protected $fillable = [
        'name', 'phone', 'course_id', 'group_id', 'source', 'status', 'lost_reason', 'owner_id', 'next_contact_on',
        'trial_session_id', 'comment', 'notes', 'telegram_id', 'student_id', 'converted_at', 'consent_at', 'meta', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime', 'consent_at' => 'datetime', 'meta' => 'array',
            'telegram_id' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function trialSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'trial_session_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function toApi(): array
    {
        $trial = $this->relationLoaded('trialSession') ? $this->trialSession : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'source' => $this->source,
            'status' => $this->status,
            'lost_reason' => $this->lost_reason,
            'course' => $this->relationLoaded('course') && $this->course ? ['id' => $this->course->id, 'title' => $this->course->title] : null,
            'group' => $this->relationLoaded('group') && $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null,
            'owner' => $this->relationLoaded('owner') && $this->owner ? ['id' => $this->owner->id, 'name' => trim($this->owner->first_name.' '.$this->owner->last_name)] : null,
            'next_contact_on' => $this->next_contact_on ? substr((string) $this->next_contact_on, 0, 10) : null,
            'trial' => $trial ? [
                'session_id' => $trial->id, 'group_id' => $trial->group_id, 'group_name' => $trial->group?->name,
                'starts_at' => $trial->starts_at->toIso8601String(), 'cancelled' => $trial->cancelled,
            ] : null,
            'comment' => $this->comment,
            'notes' => $this->notes,
            'student_id' => $this->student_id,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'telegram' => $this->telegram_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
