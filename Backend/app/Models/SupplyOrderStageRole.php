<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §39: snapshot por pedido (no usa Tenantable — no tiene columna tenant_id; se
 * scopea siempre a través de su SupplyOrder padre, que sí es Tenantable).
 */
class SupplyOrderStageRole extends Model
{
    protected $fillable = ['supply_order_id', 'stage', 'job_role_id'];

    public function supplyOrder()
    {
        return $this->belongsTo(SupplyOrder::class);
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }
}
