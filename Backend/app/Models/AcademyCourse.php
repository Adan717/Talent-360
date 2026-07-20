<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class AcademyCourse extends Model
{
    use Tenantable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'quiz_data' => 'array',
    ];
}
