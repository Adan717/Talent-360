<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class FreemiumComplianceCheck extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id', 'period', 'status', 'proof_note', 'proof_url',
        'reviewed_by', 'review_note', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
