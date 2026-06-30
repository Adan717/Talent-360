<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class Interview extends Model
{
    use Tenantable;

    protected $fillable = [
        'candidate_id',
        'interview_date',
        'interview_time',
        'interviewer_name',
        'notes',
        'tenant_id'
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
