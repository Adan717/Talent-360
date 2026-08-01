<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\SoftDeletes;

class WeeklyPayroll extends Model
{
    use Tenantable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'employee_approved_at' => 'datetime',
        'admin_approved_at' => 'datetime', // H23
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /** Quién autorizó el pago por parte de la empresa (H23). */
    public function aprobadaPor()
    {
        return $this->belongsTo(User::class, 'admin_approved_by');
    }
}
