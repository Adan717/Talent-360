<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class MealPhotoEvidence extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'employee_id', 'date', 'type', 'url', 'path'];

    protected $casts = [
        'date' => 'date',
    ];
}
