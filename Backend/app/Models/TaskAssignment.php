<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaskAssignment extends Model
{
    use Tenantable;

    use HasFactory;

    protected $fillable = [
        'id', 'task_id', 'user_id', 'status', 'started_at_mins', 'expected_end_time_mins',
        'completed_at_mins', 'assigned_from_routine_id', 'assistant_data', 'accumulated_mins',
        'validated_by', 'validation_feedback', 'date', 'points_awarded'
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'assistant_data' => 'array',
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
