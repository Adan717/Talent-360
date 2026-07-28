<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyWorkPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'daily_work_plans';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'plan_date',
        'ai_summary',
        'missing_roles_impact',
        'assignments_json',
        'status',
        'created_by_user_id',
        'created_by_name_snapshot',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'missing_roles_impact' => 'array',
        'assignments_json' => 'array',
    ];
}
