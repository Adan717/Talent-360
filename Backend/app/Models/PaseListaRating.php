<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class PaseListaRating extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id', 'store_id', 'employee_id', 'rated_by_employee_id',
        'date', 'presentacion', 'imagen', 'energia',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
