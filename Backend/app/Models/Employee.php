<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use App\Traits\Tenantable;

class Employee extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'email',
        'phone',
        'employee_id',
        'job_role_id',
        // A quién le reporta esta persona (organigrama). Sin esto en la lista, el
        // `update(['report_to' => ...])` de updateReportTo se descartaba en silencio.
        'report_to',
        'salary',
        'base_salary',
        'salario_diario',
        'periodicidad_captura',
        'curp',
        'rfc',
        'nss',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'hire_date',
        // 2026-08-16: cuándo y por qué se fue. Sin esto, dar de baja no dejaba fecha y la
        // rotación no se podía medir (ver el reporte de rotación).
        'termination_date',
        'termination_reason',
        'contract_type',
        'is_active_employee',
        'shiftStart',
        'shiftEnd',
        'mealMinutes',
        'restDay',
        'pin_code',
        'security_pin',
        'invite_token',
        'portadorLlaves',
        'avatar',
        'lunch_time',
        'clock_preferences',
        'allowed_modules',
        'allowed_features'
    ];

    protected $appends = ['role', 'has_kiosk_pin'];

    // security_pin (testigos/Silla, línea §1–§42) y el PIN de kiosko (hash + blind index, R54)
    // NUNCA deben salir en un toArray()/JSON.
    protected $hidden = [
        'security_pin',
        'kiosk_pin_hash',
        'kiosk_pin_lookup',
    ];

    protected $casts = [
        'is_active_employee' => 'boolean',
        'salary' => 'decimal:2',
        'base_salary' => 'decimal:2',
        'salario_diario' => 'decimal:2',
        'mealMinutes' => 'integer',
        // R87: garantiza int en el payload de auth (0 = alarma desactivada). El cast lo fija a
        // nivel de modelo independientemente del driver/versión de PHP; el FE distingue 0 (off)
        // de null/positivo.
        'pre_shift_alarm_minutes' => 'integer',
        'clock_preferences' => 'array',
        // Resync 3 (línea del jefe): módulos/funciones permitidos por colaborador.
        'allowed_modules' => 'array',
        'allowed_features' => 'array'
    ];

    public function getRoleAttribute()
    {
        return $this->user ? $this->user->role : 'empleado';
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }

    // ---- PIN de kiosko (R54) -------------------------------------------------------

    /**
     * Blind index del PIN: HMAC-SHA256 con el APP_KEY como pepper. Determinista (permite lookup y
     * unique) pero irreversible sin la clave, así que un leak sólo de BD no expone los PINs. El
     * pepper vive en la config, no en la tabla.
     *
     * OJO: rotar APP_KEY invalida TODOS los PINs de kiosko (habría que reasignarlos) — igual que
     * invalida sesiones y datos cifrados. Es el precio de no guardar un pepper aparte.
     */
    /**
     * ¿Tiene PIN de kiosco? Es lo ÚNICO sobre el PIN que la ficha del colaborador puede saber:
     * el hash y el índice están en `$hidden` y el PIN en claro no se guarda nunca.
     */
    public function getHasKioskPinAttribute(): bool
    {
        return !empty($this->attributes['kiosk_pin_hash']);
    }

    public static function kioskPinLookup(string $pin): string
    {
        return hash_hmac('sha256', $pin, config('app.key'));
    }

    /**
     * Asigna (o resetea) el PIN de kiosko: guarda el bcrypt (verificación) y el blind index
     * (lookup). No persiste por sí solo — el caller hace save() dentro de su propia validación de
     * unicidad, para no dejar un índice a medias si la unicidad falla.
     */
    public function setKioskPin(string $pin): void
    {
        $this->kiosk_pin_hash = Hash::make($pin);
        $this->kiosk_pin_lookup = static::kioskPinLookup($pin);
    }

    /**
     * ¿Este empleado puede fichar por kiosko? Cierra el follow-up de R53 en el servidor: necesita
     * PUESTO (job_role_id) y USUARIO vinculado (para poder registrar el ponche; un huérfano R40 no).
     */
    public function canClockIn(): bool
    {
        return $this->job_role_id !== null && $this->user_id !== null;
    }

    /**
     * Costo por minuto trabajado, para `task_cost`.
     *
     * Usa `salario_diario` (unidad LFT, jornada de 480 min) cuando el expediente lo declara.
     * Para expedientes LEGADOS conserva el statu quo (`base_salary/480`, default $300) — que
     * esta INFLADO ~6x cuando lo capturado era semanal (documentado en
     * docs/NOMINA_PERIODICIDAD_MULTIEMPRESA_2026-08-02.md como "no confiable"). Se corrige
     * recapturando el sueldo con periodicidad, no adivinando aqui: task_cost no paga nomina
     * (verificado), asi que el legado mantiene su valor historico hasta la recaptura.
     */
    public function costoPorMinuto(): float
    {
        if ($this->salario_diario !== null && (float) $this->salario_diario > 0) {
            return (float) $this->salario_diario / 480.0;
        }

        $legado = (float) ($this->base_salary ?? 0) > 0 ? (float) $this->base_salary : 300.00;

        return $legado / 480.0;
    }
}
