<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class ObsidianExamAttempt extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'exam_id',
        'job_role_title_at_time',
        'user_name_at_time',
        'score',
        'total_questions',
        'passed',
        'answers'
    ];

    protected $casts = [
        'answers' => 'array',
        'passed' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(ObsidianUser::class, 'user_id');
    }

    public function exam()
    {
        return $this->belongsTo(ObsidianExam::class, 'exam_id');
    }
}
