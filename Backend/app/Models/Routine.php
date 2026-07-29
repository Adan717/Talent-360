<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Routine extends Model
{
    use Tenantable, HasFactory, SoftDeletes;

    protected $fillable = [
        // Resync 3: unión — su `tenant_id` (scripts de seed/clonado) + nuestro
        // `trigger_time` (rutinas de horario fijo, T15). El tenant_id de los requests
        // reales lo fija el servidor (sync/controllers lo escriben explícito).
        'id', 'tenant_id', 'title', 'target_role_id', 'trigger', 'trigger_time', 'assign_mode'
    ];

    public function tasks()
    {
        return $this->belongsToMany(Task::class);
    }
}
