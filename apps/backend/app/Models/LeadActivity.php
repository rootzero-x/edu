<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ariza tarixi: qo'ng'iroq, izoh, holat o'zgarishi, tizim yozuvi (takroriy ariza va h.k.). */
class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'user_id', 'type', 'text', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'text' => $this->text,
            'data' => $this->data,
            'user' => $this->relationLoaded('user') && $this->user ? trim($this->user->first_name.' '.$this->user->last_name) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
