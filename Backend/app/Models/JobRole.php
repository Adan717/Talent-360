<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class JobRole extends Model
{
    use Tenantable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'esAperturador' => 'boolean',
        'requiereJustificante' => 'boolean',
        'puedeEmitirAvisos' => 'boolean',
        'aplicaLeySilla' => 'boolean',
        'evaluacion360Activa' => 'boolean',
        'tiempoTolerancia' => 'integer',
        'jerarquiaLlaves' => 'integer',
        'reports_to_role_id' => 'integer',
        'org_parent_role_id' => 'integer',
        'nivel_mando' => 'integer',
        'reports_to_role_ids' => 'array',
        'late_penalty_multiplier' => 'float',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'job_role_id');
    }

    public function vacancies()
    {
        return $this->hasMany(Vacancy::class, 'job_role_id');
    }

    public function reportsTo()
    {
        return $this->belongsTo(JobRole::class, 'reports_to_role_id');
    }

    public function orgParent()
    {
        return $this->belongsTo(JobRole::class, 'org_parent_role_id');
    }

    public function isSupervisorOf(JobRole $employeeRole): bool
    {
        $visited = [];
        $current = $employeeRole;
        
        while ($current && !in_array($current->id, $visited)) {
            $visited[] = $current->id;
            
            if ($this->id === $current->reports_to_role_id) {
                return true;
            }
            
            if (is_array($current->reports_to_role_ids) && in_array($this->id, $current->reports_to_role_ids)) {
                return true;
            }
            
            $current = $current->reportsTo;
        }
        
        return false;
    }
}
