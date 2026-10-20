<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonMaterial extends Model
{
    protected $fillable = ['lesson_id', 'type', 'title', 'url', 'file_id', 'content', 'position'];

    /** Yuklangan fayl (type=FILE, url o'rniga): nomi, turi, hajmi — yuklab olish havolasi alohida so'raladi. */
    protected $with = ['file:id,original_name,mime,size'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
