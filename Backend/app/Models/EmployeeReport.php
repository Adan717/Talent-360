<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Tenantable;

class EmployeeReport extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'reporter_id',
        'accused_id',
        'type',
        'details',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function accused()
    {
        return $this->belongsTo(User::class, 'accused_id');
    }
}
