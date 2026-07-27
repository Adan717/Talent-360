<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Traits\Tenantable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

// `avatar` y `has_completed_induction` faltaban aquí, y su ausencia era SILENCIOSA: Eloquent
// descarta lo no-fillable sin lanzar nada (AcademyController cerraba la inducción y nunca
// persistía). `phone` viene de la línea del jefe (migración add-phone). `job_role_id`/
// `pre_shift_alarm_minutes` en users son legacy de la línea §1–§42 (la fuente canónica es el
// EXPEDIENTE; ver expediente()/R73) — se conservan fillable mientras las columnas existan
// (drop diferido de F2).
#[Fillable(['name', 'email', 'phone', 'password', 'tenant_id', 'role', 'is_active', 'google_id', 'apple_id', 'samsung_id', 'avatar', 'has_completed_induction', 'job_role_id', 'pre_shift_alarm_minutes'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Tenantable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }

    /**
     * El puesto vive SÓLO en el EXPEDIENTE (`employees.job_role_id`): es lo que edita RRHH y lo
     * que `ClockController::getState` manda al frontend. `users.job_role_id` era un duplicado
     * legacy que cada escritor debía recordar sincronizar (R41/R73): el expediente es la ÚNICA
     * fuente. Se resuelve desde el expediente cuando existe; sin expediente NO hay puesto (null).
     *
     * OJO (bug cazado en vivo): la consulta va con `withoutGlobalScopes()` + filtro EXPLÍCITO por
     * el tenant del propio usuario — en `/login` todavía NO hay usuario autenticado y el
     * TenantScope aplicaría `whereRaw('1 = 0')`.
     */
    public function expediente(): ?Employee
    {
        if ($this->relationLoaded('employee')) {
            return $this->getRelation('employee');
        }

        return Employee::withoutGlobalScopes()
            ->where('user_id', $this->getKey())
            ->where('tenant_id', $this->attributes['tenant_id'] ?? null)
            ->first();
    }

    public function authoritativeJobRoleId()
    {
        $employee = $this->expediente();

        return $employee?->job_role_id;
    }

    /**
     * ¿Este usuario puede fichar? (Ronda 53)
     *
     * Los roles son DOS EJES INDEPENDIENTES (decisión de producto 2026-07-15): acceso a la
     * plataforma ⊥ puesto de trabajo. Fichar es operativo → depende del **puesto**, no del rol de
     * acceso. Se exige EXPEDIENTE (jornada/comida/aperturas viven sólo en employees, R32/R45) y
     * PUESTO (política de tolerancia, R41). Mira `is_active_employee` (R89): un colaborador dado
     * de baja no puede fichar aunque su sesión siga viva.
     */
    public function canClockIn(?Employee $employee = null): bool
    {
        $employee = $employee ?? $this->expediente();

        return $employee !== null
            && $employee->job_role_id !== null
            && $employee->is_active_employee !== false;
    }

    /**
     * Payload del usuario para el contrato de auth (`/login`, `/me`, login social). El frontend usa
     * `currentUser.job_role_id` para la política de tolerancia y las rutinas `on_checkin`; se
     * resuelve desde el EXPEDIENTE. También viajan los datos de JORNADA (mealMinutes, shiftStart,
     * shiftEnd, restDay), la alarma de traslado (R87) y el contador real de bloqueos de puntualidad
     * (R102). Sin expediente NO se inventan.
     */
    public function toAuthPayload(): array
    {
        $employee = $this->expediente();

        $payload = array_merge($this->toArray(), [
            'job_role_id' => $employee?->job_role_id,
            // R53: el gate de la ruta `/empleado` lee esto de `currentUser`. Se le pasa el expediente
            // ya resuelto para no repetir la query — NO se usa setRelation() porque `toArray()`
            // serializaría el expediente entero (con su email) dentro del payload de auth.
            'can_clock_in' => $this->canClockIn($employee),
        ]);

        if ($employee) {
            $payload['mealMinutes'] = $employee->mealMinutes;
            $payload['shiftStart'] = $employee->shiftStart;
            $payload['shiftEnd'] = $employee->shiftEnd;
            $payload['restDay'] = $employee->restDay;
            // R87: alarma de traslado (minutos antes del turno; 0/null = desactivada).
            $payload['pre_shift_alarm_minutes'] = $employee->pre_shift_alarm_minutes;
            // R102: estado #1 del dial con datos REALES — cuenta las entradas tardías AUTORIZADAS
            // (audit_logs `late_entry_unlocked`) desde el último curso de Puntualidad aprobado.
            $payload['punctuality_lockout_count'] = (int) \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('tenant_id', $this->tenant_id)
                ->where('user_id', $this->id)
                ->where('type', 'late_entry_unlocked')
                ->when($employee->punctuality_reset_at, fn ($q) => $q->where('created_at', '>', $employee->punctuality_reset_at))
                ->count();
        }

        return $payload;
    }
}
