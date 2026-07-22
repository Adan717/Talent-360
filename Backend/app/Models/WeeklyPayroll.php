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
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
