<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformanceEvaluation extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'evaluator_user_id',
        'evaluated_user_id',
        'teamwork_score',
        'performance_score',
        'attitude_score',
        'comments',
        'tenant_id'
    ];

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }

    public function evaluated()
    {
        return $this->belongsTo(User::class, 'evaluated_user_id');
    }
}
