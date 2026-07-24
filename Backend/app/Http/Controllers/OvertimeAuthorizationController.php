<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\OvertimeAuthorization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Laborar Horas Extras / Feriado REAL (Ronda 81, Fase 1 / T1.2 del plan v2).
 *
 * El teatro que se cierra: el botón "Laborar Horas Extras" pedía QR/PIN de supervisor pero en plan
 * free el PIN no se validaba contra NADA, el "desbloqueo" vivía en un useState del cliente, y el
 * bloqueo de feriado de ClockService rechazaba el check_in igual. Ahora la credencial del SUPERVISOR
 * se valida aquí server-side y la autorización queda en `overtime_authorizations`; el bloqueo de
 * feriado se levanta sólo con esa fila (spec §5: "Desbloquea el dial para trabajar en día
 * libre/feriado").
 *
 * Credenciales aceptadas (las 2 del modal del reloj):
 *  - `qr_token`: QR dinámico de 60s del supervisor (tabla supervisor_qr_tokens). El token se acuña a
 *    nombre del propio supervisor (R81 endureció generateSupervisorQR: gate de rol + supervisor_id
 *    forzado a auth()->id()), así que su dueño es de fiar; aquí se verifica que sea admin/supervisor
 *    ACTIVO del mismo tenant y el consumo es atómico.
 *  - `supervisor_pin`: el PIN de kiosko (R54) de un mando ACTIVO — blind index HMAC + verificación
 *    bcrypt + rate-limit de fallos (la defensa real de un PIN de 6 dígitos online).
 *
 * R75: nadie se auto-autoriza — la credencial debe ser de OTRA persona con autoridad.
 */
class OvertimeAuthorizationController extends Controller
{
    public function grant(Request $request)
    {
        $request->validate([
            'qr_token' => ['required_without:supervisor_pin', 'nullable', 'string', 'max:100'],
            'supervisor_pin' => ['required_without:qr_token', 'nullable', 'string', 'regex:/\A\d{6}\z/'],
        ]);

        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $qrRow = null;
        if ($request->filled('supervisor_pin')) {
            // ---- PIN de kiosko del supervisor (R54) -------------------------------------
            // Sólo cuentan los FALLOS (patrón R54/R64): un supervisor autorizando a varios en el
            // mismo día no consume presupuesto. La key lleva al empleado solicitante: un atacante
            // se bloquea a sí mismo sin negar la autorización en otros dispositivos.
            $rateKey = 'overtime-pin-fail:' . $tenantId . ':' . $user->id;
            if (RateLimiter::tooManyAttempts($rateKey, 5)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Demasiados intentos fallidos. Espera un momento e inténtalo de nuevo.',
                ], 429);
            }

            $lookup = Employee::kioskPinLookup($request->supervisor_pin);
            $supEmployee = Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('kiosk_pin_lookup', $lookup)
                ->first();
            $supervisor = $supEmployee ? User::withoutGlobalScopes()->find($supEmployee->user_id) : null;

            // TODAS las fallas del PIN colapsan al MISMO 422 genérico + rate-hit: PIN no encontrado,
            // hash que no verifica, o dueño SIN autoridad/ARCHIVADO. Distinguirlas (p.ej. 403 "no es
            // supervisor" vs 422 "no reconocido") sería un ORÁCULO: revelaría que un 6-dígitos dado es
            // el PIN real de ALGÚN empleado (usable en el kiosko). Y no gastar presupuesto en el PIN
            // "correcto pero sin autoridad" daría adivinanzas gratis.
            $pinValido = $supEmployee
                && $supEmployee->kiosk_pin_hash
                && Hash::check($request->supervisor_pin, $supEmployee->kiosk_pin_hash);
            if (!$pinValido || !$this->esMandoActivo($supervisor, $tenantId)) {
                RateLimiter::hit($rateKey, 60);
                return response()->json(['success' => false, 'message' => 'PIN no reconocido.'], 422);
            }

            $method = 'pin';
        } else {
            // ---- QR dinámico del supervisor ---------------------------------------------
            $qrRow = DB::table('supervisor_qr_tokens')->where('token', $request->qr_token)->first();
            if (!$qrRow || $qrRow->is_used || Carbon::parse($qrRow->expires_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código QR inválido, usado o expirado (límite 60s).',
                ], 422);
            }

            $supervisor = User::withoutGlobalScopes()->find($qrRow->supervisor_id);
            // El QR NO es un secreto adivinable (16 bytes aleatorios), así que sí se puede ser
            // específico sobre por qué no autoriza sin abrir un oráculo.
            if (!$this->esMandoActivo($supervisor, $tenantId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La credencial no pertenece a un supervisor o administrador ACTIVO de tu empresa.',
                ], 403);
            }

            $method = 'qr';
        }

        // R75: nadie se auto-autoriza — el control existe para que decida alguien MÁS.
        if ((int) $supervisor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes autorizarte a ti mismo. Debe hacerlo otro supervisor o administrador.',
            ], 403);
        }

        // El QR se consume AL FINAL y de forma ATÓMICA: un intento fallido de otra persona no quema
        // el token del supervisor, y dos requests concurrentes con el mismo token no autorizan dos
        // veces (el perdedor del UPDATE condicional recibe 422).
        if ($method === 'qr') {
            $consumed = DB::table('supervisor_qr_tokens')
                ->where('id', $qrRow->id)
                ->where('is_used', false)
                ->update(['is_used' => true, 'updated_at' => now()]);
            if ($consumed === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código QR inválido, usado o expirado (límite 60s).',
                ], 422);
            }
        }

        // Fecha en la tz del TENANT (R9) y tipo derivado server-side: feriado si HOY está en
        // lft_holidays (bloqueante o no); si no, es labor en día de descanso.
        $timezone = TenantTimezone::for($tenantId);
        $date = Carbon::now($timezone)->format('Y-m-d');
        $esFeriado = DB::table('lft_holidays')
            ->where('tenant_id', $tenantId)
            ->where('date', $date)
            ->exists();
        $kind = $esFeriado ? 'holiday' : 'rest_day';

        // Race-safe e idempotente por (tenant, user, día) — lección R8/R51: insertOrIgnore, nunca
        // try/catch de un unique (aborta la tx en Postgres).
        DB::table('overtime_authorizations')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'kind' => $kind,
            'authorized_by' => $supervisor->id,
            'method' => $method,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rastro de auditoría PERSISTENTE server-side (antes lo posteaba el cliente, que un cliente
        // hostil podía saltarse; y la rama nueva del FE lo había dejado de mandar). audit_logs es lo
        // que el admin ve vía /sync/state. `date`/`timestamp_str` son NOT NULL (sin default).
        $ahora = Carbon::now($timezone);
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'type' => $esFeriado ? 'holiday_unlocked' : 'overtime_unlocked',
            'timestamp_str' => $ahora->format('H:i:s'),
            'reason' => 'Autorizado por ' . $supervisor->name . ' mediante ' . ($method === 'qr' ? 'QR dinámico' : 'PIN'),
            'details' => $esFeriado
                ? 'Labor en día feriado habilitada por un mando (pago según LFT aplicable).'
                : 'Horas extras / día de descanso habilitadas por un mando.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $esFeriado
                ? 'Labor en día feriado autorizada. Ya puedes registrar tu entrada.'
                : 'Horas extras autorizadas. Ya puedes registrar tu entrada.',
            // Sólo escalares que el FE necesita (no se filtra authorized_by/method al empleado).
            'kind' => $kind,
            'date' => $date,
        ], 201);
    }

    /**
     * Estado de HOY para el reloj (patrón R80 panic/mine): ¿hoy es feriado bloqueante? ¿tengo ya una
     * autorización? Rehidrata el dial tras un reload SIN localStorage ni fecha del dispositivo (la
     * verdad —feriado y autorización— es del servidor, en la tz del tenant).
     */
    public function today()
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['is_holiday_today' => false, 'authorized' => false]);
        }

        $timezone = TenantTimezone::for($tenantId);
        $date = Carbon::now($timezone)->format('Y-m-d');

        $holiday = DB::table('lft_holidays')
            ->where('tenant_id', $tenantId)
            ->where('date', $date)
            ->first();

        $authorized = OvertimeAuthorization::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->exists();

        return response()->json([
            'is_holiday_today' => (bool) $holiday,
            'holiday_blocks' => $holiday ? (bool) $holiday->block_app : false,
            'holiday_name' => $holiday->name ?? null,
            'authorized' => $authorized,
        ]);
    }

    /** ¿El usuario resuelto es un mando (admin/supervisor) ACTIVO del tenant? */
    private function esMandoActivo(?User $supervisor, int $tenantId): bool
    {
        return $supervisor
            && (int) $supervisor->tenant_id === (int) $tenantId
            && $supervisor->is_active
            && in_array($supervisor->role, ['admin', 'supervisor'], true);
    }
}
