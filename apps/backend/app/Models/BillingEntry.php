<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** To'lovlar jurnali yozuvi (o'zgarmas; faqat bekor qilinadi). */
class BillingEntry extends Model
{
    public const CHARGE = 'CHARGE';

    public const PAYMENT = 'PAYMENT';

    public const ADJUSTMENT = 'ADJUSTMENT';

    protected $fillable = [
        'student_id', 'group_id', 'type', 'amount', 'entry_date', 'method', 'note', 'meta', 'dedupe_key', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'entry_date' => 'date:Y-m-d', 'meta' => 'array', 'voided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'group_id' => $this->group_id,
            'group' => $this->relationLoaded('group') ? $this->group?->name : null,
            'type' => $this->type,
            'amount' => $this->amount,
            'entry_date' => $this->entry_date->toDateString(),
            'method' => $this->method,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'voided' => $this->voided_at !== null,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
        ];
    }
}
