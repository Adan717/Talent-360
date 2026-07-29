<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantSubscriptionHistory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tenant_subscription_histories';

    protected $fillable = [
        'tenant_id',
        'billing_plan_id',
        'plan_code',
        'plan_name_at_time',
        'monthly_price_at_time',
        'currency',
        'billing_cycle',
        'modules_count_at_time',
        'modules_snapshot_json',
        'features_snapshot_json',
        'max_users_at_time',
        'active_users_at_time',
        'change_reason',
        'effective_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'monthly_price_at_time' => 'decimal:2',
        'modules_count_at_time' => 'integer',
        'max_users_at_time' => 'integer',
        'active_users_at_time' => 'integer',
        'modules_snapshot_json' => 'array',
        'features_snapshot_json' => 'array',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function billingPlan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }
}
