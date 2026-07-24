<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Salida Anticipada REAL (Ronda 92, Fase 4). Espejo de OvertimeAuthorizationController (R81).
 *
 * El teatro que se cierra: el modal de salida anticipada validaba el QR con un endpoint hueco
 * (validate-qr: sin tenant, sin rol, sin registro) y en free el PIN era `length >= 4` sin tocar el
 * servidor; la "autorización" viajaba como un STRING en `details.note` que cualquier cliente hostil
 * podía redactar. Ahora la credencial del SUPERVISOR se valida aquí server-side, la autorización queda
 * en `early_departure_authorizations`, y processPunch estampa `details.early_departure.authorized`
 * consultando esa fila — no el texto del cliente.
 *
 * Credenciales aceptadas (las 2 del modal): `qr_token` (QR dinámico 60s) o `supervisor_pin` (PIN de
 * kiosko R54, blind index + bcrypt + rate-limit). R75: nadie se auto-autoriza.
 */
class EarlyDepartureAuthorizationController extends Controller
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
            // PIN de kiosko del supervisor (R54). Sólo cuentan los FALLOS; key por solicitante
            // (patrón R54/R64/R81).
            $rateKey = 'early-dep-pin-fail:' . $tenantId . ':' . $user->id;
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

            // Anti-oráculo (R81): TODAS las fallas colapsan al mismo 422 genérico + rate-hit.
            $pinValido = $supEmployee
                && $supEmployee->kiosk_pin_hash
                && Hash::check($request->supervisor_pin, $supEmployee->kiosk_pin_hash);
            if (!$pinValido || !$this->esMandoActivo($supervisor, $tenantId)) {
                RateLimiter::hit($rateKey, 60);
                return response()->json(['success' => false, 'message' => 'PIN no reconocido.'], 422);
            }

            $method = 'pin';
        } else {
            // QR dinámico del supervisor. A diferencia del legacy validate-qr (hueco: ni tenant ni
            // rol ni registro), aquí el dueño debe ser un mando ACTIVO del MISMO tenant.
            $qrRow = DB::table('supervisor_qr_tokens')->where('token', $request->qr_token)->first();
            if (!$qrRow || $qrRow->is_used || Carbon::parse($qrRow->expires_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código QR inválido, usado o expirado (límite 60s).',
                ], 422);
            }

            $supervisor = User::withoutGlobalScopes()->find($qrRow->supervisor_id);
            if (!$this->esMandoActivo($supervisor, $tenantId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La credencial no pertenece a un supervisor o administrador ACTIVO de tu empresa.',
                ], 403);
            }

            $method = 'qr';
        }

        // R75: nadie se auto-autoriza.
        if ((int) $supervisor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes autorizarte a ti mismo. Debe hacerlo otro supervisor o administrador.',
            ], 403);
        }

        // Consumo atómico del QR AL FINAL (R81): un intento fallido ajeno no quema el token; dos
        // requests concurrentes no autorizan dos veces.
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

        $timezone = TenantTimezone::for($tenantId);
        $date = Carbon::now($timezone)->format('Y-m-d');

        // Race-safe e idempotente por (tenant, user, día) — insertOrIgnore (lección R8/R51).
        DB::table('early_departure_authorizations')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'authorized_by' => $supervisor->id,
            'method' => $method,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rastro de auditoría PERSISTENTE server-side (lo que el admin ve vía /sync/state).
        $ahora = Carbon::now($timezone);
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'type' => 'early_departure_authorized',
            'timestamp_str' => $ahora->format('H:i:s'),
            'reason' => 'Salida anticipada autorizada por ' . $supervisor->name . ' mediante ' . ($method === 'qr' ? 'QR dinámico' : 'PIN'),
            'details' => 'El check_out de hoy quedará marcado como salida anticipada AUTORIZADA.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Salida anticipada autorizada. Ya puedes registrar tu salida.',
            'date' => $date,
        ], 201);
    }

    /** ¿El usuario resuelto es un mando (admin/supervisor) ACTIVO del tenant? (idéntico a R81) */
    private function esMandoActivo(?User $supervisor, int $tenantId): bool
    {
        return $supervisor
            && (int) $supervisor->tenant_id === (int) $tenantId
            && $supervisor->is_active
            && in_array($supervisor->role, ['admin', 'supervisor'], true);
    }
}
