<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class CompanyFeature extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'feature_key',
        'is_enabled',
        'enabled_from',
        'enabled_until',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'enabled_from' => 'datetime',
        'enabled_until' => 'datetime',
    ];

    public function platformFeature()
    {
        return $this->belongsTo(PlatformFeature::class, 'feature_key', 'feature_key');
    }
}
