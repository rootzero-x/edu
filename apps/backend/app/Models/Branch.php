<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O'quv markazi bitta filialda ishlaydi (TISU). Jadval va guruhlardagi branch_id saqlanadi,
 * lekin filiallar API/UI'dan olib tashlangan — barcha guruhlar shu yagona filialga tegishli.
 */
class Branch extends Model
{
    public const DEFAULT_NAME = 'TISU';

    /** Yagona filial id'si (bo'lmasa yaratiladi). */
    public static function primaryId(): int
    {
        return (int) (static::query()->orderBy('id')->value('id') ?? static::create(['name' => self::DEFAULT_NAME])->id);
    }

    protected $fillable = ['name', 'address', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
