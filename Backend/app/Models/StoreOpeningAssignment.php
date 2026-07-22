<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class StoreOpeningAssignment extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'employee_id',
        'priority_order',
        'can_open_store',
        'can_close_store',
        'has_keys',
        'is_active',
    ];

    protected $casts = [
        'can_open_store' => 'boolean',
        'can_close_store' => 'boolean',
        'has_keys' => 'boolean',
        'is_active' => 'boolean',
        'priority_order' => 'integer',
    ];

    // §29: employee_id es employees.id (migración 2026_07_07_192928), pero el
    // frontend necesita users.id para comparar contra el usuario autenticado. Se
    // expone resuelto aquí para no obligar a cada endpoint a repetir la traducción.
    // Requiere que 'employee' venga cargado con 'user_id' en el select.
    protected $appends = ['resolved_user_id'];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function getResolvedUserIdAttribute()
    {
        return $this->relationLoaded('employee') ? $this->employee?->user_id : null;
    }
}
