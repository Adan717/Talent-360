<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use Tenantable, HasFactory, SoftDeletes;

    protected $fillable = [
        'id', 'tenant_id', 'title', 'estimated_mins', 'points', 'priority', 'category', 'target_type',
        'target_id', 'assistant_type', 'assistant_prompt', 'is_auto_capture', 'validation_mode',
        'can_be_done_sitting', 'scheduled_time', 'description', 'validation_criteria',
        'frequency', 'evidence_type', 'procedure_steps', 'is_validated', 'academy_lesson_id',
        'ai_comparison_enabled', 'ai_reference_images', 'ai_tolerance_description',
    ];

    public function academyLesson()
    {
        return $this->belongsTo(AcademyCourse::class, 'academy_lesson_id');
    }

    protected $casts = [
        'is_auto_capture' => 'boolean',
        'can_be_done_sitting' => 'boolean',
        'procedure_steps' => 'array',
        'validation_criteria' => 'array',
        'is_validated' => 'boolean',
        'ai_comparison_enabled' => 'boolean',
        'ai_reference_images' => 'array',
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
