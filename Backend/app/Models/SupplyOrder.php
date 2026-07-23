<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class SupplyOrder extends Model
{
    use Tenantable;

    /**
     * §39: etapas de la cadena de pedidos, en orden. Avanzar significa pasar a la
     * siguiente de esta lista; la última (listo_exhibir) genera la tarea de exhibición.
     */
    public const STAGES = ['generado', 'por_llegar', 'recibido', 'almacenado', 'listo_exhibir'];

    protected $fillable = [
        'tenant_id', 'supplier_name', 'created_by_user_id', 'status', 'expected_date', 'notes',
    ];

    protected $casts = [
        'expected_date' => 'date',
    ];

    public function stageRoles()
    {
        return $this->hasMany(SupplyOrderStageRole::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
