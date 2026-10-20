<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'image_url', 'audience', 'audience_role', 'group_id', 'is_public', 'pinned', 'notify_telegram', 'published_at', 'notified_at', 'created_by'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'pinned' => 'boolean', 'notify_telegram' => 'boolean', 'published_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }
}
