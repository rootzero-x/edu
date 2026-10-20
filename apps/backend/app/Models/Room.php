<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = ['name', 'capacity', 'note', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'capacity' => 'integer'];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'room_id');
    }
}
