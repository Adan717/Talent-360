<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Task extends Model
{
    use Tenantable;

    use HasFactory;

    protected $fillable = [
        'id', 'title', 'estimated_mins', 'points', 'priority', 'category', 'target_type',
        'target_id', 'assistant_type', 'assistant_prompt', 'is_auto_capture', 'validation_mode',
        'can_be_done_sitting', 'scheduled_time', 'description', 'validation_criteria', 
        'frequency', 'evidence_type', 'procedure_steps', 'is_validated'
    ];

    protected $casts = [
        'is_auto_capture' => 'boolean',
        'can_be_done_sitting' => 'boolean',
        'procedure_steps' => 'array',
        'validation_criteria' => 'array',
        'is_validated' => 'boolean',
    ];

    public function routines()
    {
        return $this->belongsToMany(Routine::class);
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }
}
