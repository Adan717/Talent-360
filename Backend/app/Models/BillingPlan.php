<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class BillingPlan extends Model
{
    protected $table = 'billing_plans';
 
    protected $fillable = [
        'name',
        'code',
        'price',
        'currency',
        'billing_interval',
        'stripe_price_id',
        'features_json',
        'is_active'
    ];
 
    protected $casts = [
        'features_json' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2'
    ];
 
    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}
