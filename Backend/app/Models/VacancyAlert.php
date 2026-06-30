<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class VacancyAlert extends Model
{
    use Tenantable;

    protected $guarded = [];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
