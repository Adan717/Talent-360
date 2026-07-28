<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantModuleSubscription extends Model
{
    use SoftDeletes;

    protected $table = 'tenant_module_subscriptions';

    protected $fillable = [
        'tenant_id',
        'module_key',
        'access_type',
        'grace_days_granted',
        'proof_url',
        'proof_note',
        'expires_at',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'grace_days_granted' => 'integer',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
