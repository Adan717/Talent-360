<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class UserCourseProgress extends Model
{
    use Tenantable;

    protected $table = 'user_course_progress';
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
    ];
}
