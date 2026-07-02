<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class WeeklyPayroll extends Model
{
    use Tenantable;

    protected $guarded = [];

    protected $casts = [
        'employee_approved_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
