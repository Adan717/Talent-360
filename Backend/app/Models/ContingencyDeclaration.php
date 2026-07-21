<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class ContingencyDeclaration extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id', 'store_id', 'declared_by_user_id', 'date', 'reason', 'declared_at', 'resolved_at'
    ];

    protected $casts = [
        'date' => 'date',
        'declared_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
