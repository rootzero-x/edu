<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Yuklangan fayl metama'lumoti (files jadvali). Faylning o'zi — "uploads" diskida. */
class StoredFile extends Model
{
    protected $table = 'files';

    public const UPDATED_AT = null;

    protected $fillable = ['owner_type', 'owner_id', 'path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'owner_id' => 'integer'];
    }

    /** API uchun qisqa ko'rinish. */
    public function toApi(): array
    {
        return ['id' => $this->id, 'name' => $this->original_name, 'mime' => $this->mime, 'size' => $this->size, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
