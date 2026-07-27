<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskAssignment extends Model
{
    use Tenantable, HasFactory, SoftDeletes;

    protected $fillable = [
        'id', 'task_id', 'user_id', 'status', 'started_at_mins', 'expected_end_time_mins',
        'completed_at_mins', 'assigned_from_routine_id', 'assistant_data', 'accumulated_mins',
        'validated_by', 'validation_feedback', 'date', 'points_awarded', 'reserved_at_mins',
        // task_cost/coins_awarded existen en la tabla (migración
        // 2026_07_22_000002_add_cost_and_score_to_task_assignments_table) pero nunca se
        // agregaron aquí — tanto TaskSyncController::sync() como
        // TaskAssignmentController::update() los calculan y los pasan a update()/create(),
        // pero sin estar en $fillable el mass-assignment los descartaba en silencio.
        'task_cost', 'coins_awarded', 'origin', 'ai_validation_result', 'flagged_incomplete',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'assistant_data' => 'array',
        'ai_validation_result' => 'array',
        'flagged_incomplete' => 'boolean',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
