<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupSchedule extends Model
{
    protected $table = 'group_schedules';

    public $timestamps = false;

    protected $fillable = ['group_id', 'weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
