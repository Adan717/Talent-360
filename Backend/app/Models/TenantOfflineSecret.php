<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class TenantOfflineSecret extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'secret', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
