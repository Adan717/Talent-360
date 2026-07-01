<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class Employee extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'email',
        'phone',
        'employee_id',
        'job_role_id',
        'salary',
        'base_salary',
        'curp',
        'rfc',
        'nss',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'hire_date',
        'contract_type',
        'is_active_employee',
        'shiftStart',
        'shiftEnd',
        'mealMinutes',
        'restDay',
        'pin_code',
        'invite_token',
        'portadorLlaves',
        'avatar'
    ];

    protected $appends = ['role'];

    protected $casts = [
        'is_active_employee' => 'boolean',
        'salary' => 'decimal:2',
        'base_salary' => 'decimal:2',
        'mealMinutes' => 'integer'
    ];

    public function getRoleAttribute()
    {
        return $this->user ? $this->user->role : 'empleado';
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }
}
