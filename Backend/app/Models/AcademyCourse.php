<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class AcademyCourse extends Model
{
    use Tenantable;

    protected $guarded = [];

    protected $casts = [
        'quiz_data' => 'array',
    ];
}
