<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $table = 'attendances';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['session_id', 'student_id', 'status', 'arrived_at', 'note', 'marked_by', 'marked_at'];
}
