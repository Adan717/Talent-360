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
        'price_per_user_monthly',
        'price_per_user_yearly',
        'max_users',
        'currency',
        'billing_interval',
        'stripe_price_id',
        'features_json',
        'is_active',
        'is_provisional'
    ];
 
    protected $casts = [
        'features_json' => 'array',
        'is_active' => 'boolean',
        'is_provisional' => 'boolean',
        'price' => 'decimal:2',
        // El modelo REAL de cobro: tarifa por colaborador. La columna plana `price` se
        // conserva por compatibilidad pero ya no gobierna nada: el único lector de precios
        // es App\Support\Tarifario.
        'price_per_user_monthly' => 'decimal:2',
        'price_per_user_yearly' => 'decimal:2',
        'max_users' => 'integer'
    ];
 
    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}
