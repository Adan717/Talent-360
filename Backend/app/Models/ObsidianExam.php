<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class ObsidianExam extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'job_role_id',
        'user_id'
    ];

    public function questions()
    {
        return $this->hasMany(ObsidianExamQuestion::class, 'exam_id');
    }

    public function attempts()
    {
        return $this->hasMany(ObsidianExamAttempt::class, 'exam_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }

    public function user()
    {
        return $this->belongsTo(ObsidianUser::class, 'user_id');
    }
}
