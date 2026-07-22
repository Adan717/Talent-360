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

    // employee_id es una FK a users.id (migración 2026_06_28_030000:
    // ->constrained('users')), pese al nombre de la columna y de este método.
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
