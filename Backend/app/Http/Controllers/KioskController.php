<?php

namespace App\Http\Controllers;

use App\Events\MonitorUpdated;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Kiosko: tableta compartida en la entrada (Ronda 54, fundación backend).
 *
 * Modelo de dispositivo: la tableta se monta con la sesión de un usuario del tenant (gerente/admin);
 * de esa sesión sale el TENANT. El empleado se identifica con su PIN — NO con una sesión propia —,
 * así que el rol de acceso de quien hospeda la tableta no otorga poder de ponche: sólo ancla el
 * tenant. El PIN es la identificación real.
 */
class KioskController extends Controller
{
    protected $clockService;

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    /**
     * Asignar / resetear el PIN de kiosko de un empleado (admin/supervisor).
     */
    public function setPin(Request $request, $employeeId)
    {
        $request->validate([
            // 6 dígitos exactos: fácil en un teclado numérico y de entropía suficiente para que el
            // rate-limit haga inviable la adivinación online. `\A..\z` (no `^..$`): en PHP `$`
            // también matchea antes de un `\n` final, así que "123456\n" pasaría `^\d{6}$`.
            'pin' => ['required', 'string', 'regex:/\A\d{6}\z/'],
        ]);

        // Blocklist de PINs TRIVIALES (R54 follow-up): un 6-dígitos ya es de baja entropía; 000000 /
        // 123456 / 111111 se adivinan en los primeros intentos aun con rate-limit. Se rechazan de
        // raíz para que el admin no elija uno débil.
        if ($this->isTrivialPin($request->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'Ese PIN es demasiado fácil de adivinar. Evita dígitos repetidos o secuencias.',
            ], 422);
        }

        $tenantId = Auth::user()->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // Scope explícito por tenant: `withoutGlobalScopes` + filtro, no un find() pelado, para no
        // dejar tocar el expediente de otro tenant (patrón R10/R52).
        $employee = Employee::withoutGlobalScopes()
            ->where('id', $employeeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado en su empresa.'], 404);
        }

        // Unicidad dentro del tenant vía el blind index: dos empleados con el mismo PIN harían
        // ambigua la identificación. Se comprueba en código (además del unique de BD) para devolver
        // un 422 claro en vez de una QueryException, y se excluye al propio empleado (un reset al
        // mismo PIN no debe chocar consigo mismo).
        $lookup = Employee::kioskPinLookup($request->pin);
        $colision = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('kiosk_pin_lookup', $lookup)
            ->where('id', '!=', $employee->id)
            ->exists();

        if ($colision) {
            return response()->json([
                'success' => false,
                'message' => 'Ese PIN ya está en uso por otro colaborador. Elige uno distinto.',
            ], 422);
        }

        $employee->setKioskPin($request->pin);
        try {
            $employee->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Carrera: otro request asignó el mismo PIN entre el exists() de arriba y este save().
            // El unique de BD (employees_tenant_kiosk_pin_unique) lo atrapa → 422 claro, no un 500.
            return response()->json([
                'success' => false,
                'message' => 'Ese PIN ya está en uso por otro colaborador. Elige uno distinto.',
            ], 422);
        }

        // La respuesta NUNCA devuelve el PIN (el admin no lo ve; sólo lo resetea).
        return response()->json([
            'success' => true,
            'message' => 'PIN de kiosko actualizado.',
        ]);
    }

    /**
     * Ponche por PIN desde el kiosko.
     */
    public function punch(Request $request)
    {
        $request->validate([
            'pin' => ['required', 'string', 'regex:/\A\d{6}\z/'],
            'type' => ['required', 'string', \Illuminate\Validation\Rule::in(ClockService::PUNCH_TYPES)],
            'time' => 'nullable|string', // sólo simulador (tenant con time_mode='simulated')
            'details' => 'nullable|array',
        ]);

        $tenantId = Auth::user()->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // Rate-limit anti-fuerza-bruta. Un PIN de 6 dígitos es de baja entropía, así que ésta es la
        // defensa real contra adivinación online, no la fuerza del hash. Sólo cuentan los intentos
        // FALLIDOS (abajo): los ponches con PIN válido NO tocan el contador, así que el chorro de la
        // mañana no lo dispara — y por eso NO se limpia en éxito (limpiar permitiría a un insider con
        // un PIN propio intercalar acierto+fallos para resetear el presupuesto y adivinar sin tope).
        // La key lleva el usuario que hospeda (= la tableta): un atacante en un dispositivo se bloquea
        // a sí mismo sin negar el fichaje a las demás tabletas del tenant.
        $rateKey = 'kiosk-fail:' . $tenantId . ':' . Auth::id();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Espera un momento e inténtalo de nuevo.',
            ], 429);
        }

        $lookup = Employee::kioskPinLookup($request->pin);
        $employee = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('kiosk_pin_lookup', $lookup)
            ->first();

        // Verificación bcrypt además del match del blind index: defensa en profundidad y evita que
        // una colisión teórica del índice (o un lookup escrito a mano) resuelva un empleado. Mensaje
        // genérico: no se revela si el PIN existe o a quién pertenece.
        if (!$employee || !$employee->kiosk_pin_hash || !Hash::check($request->pin, $employee->kiosk_pin_hash)) {
            RateLimiter::hit($rateKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'PIN no reconocido.',
            ], 422);
        }

        // Enforcement server-side de "puede fichar" (cierra el follow-up de R53). En la tableta
        // compartida el gate de cliente no existe: aquí es el único sitio que lo garantiza.
        if (!$employee->canClockIn()) {
            return response()->json([
                'success' => false,
                'message' => 'Este colaborador no tiene un puesto asignado y no puede registrar asistencia.',
            ], 403);
        }

        $user = User::withoutGlobalScopes()->find($employee->user_id);
        if (!$user || (int) $user->tenant_id !== (int) $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo resolver al colaborador.',
            ], 403);
        }

        // Paridad con el bloqueo del login (R42): un usuario archivado no ficha. El kiosko NUNCA pasa
        // por login (identifica por PIN), así que esa defensa no aplica aquí — hay que replicarla.
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Este colaborador está inactivo o archivado.',
            ], 403);
        }

        // supervisor_override = false a propósito: el empleado se identifica a sí mismo con su PIN;
        // no hay un supervisor autorizando un salto de Retardo Extremo (R14) por-ponche en el kiosko.
        $details = $request->details ?? [];
        // El `sandbox_bypass` del cliente se elimina (salta el IP-lock; escape sólo de tests, el FE no
        // lo manda). Igual que en /clock/punch.
        unset($details['sandbox_bypass']);
        $details['supervisor_override'] = false;
        // R88: la tablet compartida no tiene UI de checklist de cierre → se exime del gate (no puede
        // presentarlo). Se marca aquí, en el ÚNICO endpoint del kiosko; los otros endpoints lo stripean
        // del input para que un empleado no lo falsifique y salte el checklist en su reloj personal.
        $details['via_kiosk'] = true;

        try {
            $result = $this->clockService->processPunch($user, $request->type, $request->time, $details);

            if (isset($result['success']) && $result['success']) {
                event(new MonitorUpdated($user->tenant_id));
            }

            // Se adjunta la identidad para que el kiosko muestre "Registrado: <nombre>" antes de
            // volver a reposo. Sólo nombre e ids — nada sensible.
            $result['employee'] = [
                'id' => $employee->id,
                'name' => $employee->name,
            ];

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * ¿El PIN es trivialmente adivinable? Bloquea los peores casos: todos los dígitos iguales
     * (000000…999999) y las secuencias rectas ascendentes o descendentes (123456, 654321, 012345…).
     * No pretende ser exhaustivo — es un piso; el rate-limit (R54) cubre la adivinación online.
     */
    private function isTrivialPin(string $pin): bool
    {
        // Todos los dígitos iguales.
        if (preg_match('/\A(\d)\1{5}\z/', $pin)) {
            return true;
        }

        // Secuencia recta ascendente o descendente (cada dígito ±1 respecto al anterior).
        $asc = true;
        $desc = true;
        for ($i = 1; $i < strlen($pin); $i++) {
            $diff = (int) $pin[$i] - (int) $pin[$i - 1];
            if ($diff !== 1) {
                $asc = false;
            }
            if ($diff !== -1) {
                $desc = false;
            }
        }

        return $asc || $desc;
    }
}
