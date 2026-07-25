<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Models\LateJustification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Justificantes de retardo CON EFECTO en la nómina (Ronda 82, Fase 1 / T1.3 del plan v2).
 *
 * El teatro que se cierra: el modal "Firmar y Entrar" sólo actualizaba estado local; no persistía nada
 * y la nómina lo ignoraba. Ahora el empleado solicita un justificante para el retardo de HOY, un admin
 * lo aprueba, y una fila `approved` para (tenant, user, día) hace que ClockService NO deduzca ese
 * retardo (spec §5: "adjunta justificante para mitigar deducciones … admin anula el descuento").
 *
 * Espejo estructural de LateAuthorizationController (R56): mismo request/pending/resolve, misma
 * idempotencia race-safe, mismo R75 (nadie resuelve su propio caso), misma cautela de notificación
 * tenant-scoped (R69/R80).
 */
class LateJustificationController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /** El empleado solicita el justificante de su retardo de HOY (idempotente por día). */
    public function request(Request $request)
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $timezone = TenantTimezone::for($tenantId);
        $today = Carbon::now($timezone)->format('Y-m-d');
        $reason = $request->input('reason');

        // El justificante se ANCLA al retardo REAL: la fecha del check_in tarde más reciente del
        // empleado (ventana de 2 días para tolerar el cruce de medianoche entre fichar a las 23:58 y
        // enviar el justificante a las 00:01 — si se usara `now()` a secas, la fila quedaría para el día
        // SIGUIENTE y la nómina nunca perdonaría el retardo real). Los minutos salen del mismo ponche
        // (server-side, no del cliente). Si no hay retardo en la ventana, cae a HOY (preventivo, null).
        $windowStart = Carbon::now($timezone)->subDays(2)->format('Y-m-d');
        $lateEntry = DB::table('time_entries')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('type', 'check_in')
            ->where('is_late', true)
            ->whereBetween('date', [$windowStart, $today])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();
        $date = $lateEntry ? Carbon::parse($lateEntry->date)->format('Y-m-d') : $today;
        $lateMinutes = $lateEntry->late_minutes ?? null;

        // Idempotente y race-safe SIN read-then-write (patrón R56): insertOrIgnore asegura la fila sin
        // reventar por el unique; el "reabrir a pending" es un UPDATE CONDICIONAL `status != approved`
        // (atómico), así un approved en paralelo no se pisa. Un rechazado sí se reabre (el empleado
        // insiste con más evidencia).
        DB::table('late_justifications')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'reason' => $reason,
            'requested_late_minutes' => $lateMinutes,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('late_justifications')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', '!=', 'approved')
            ->update([
                'reason' => $reason,
                'requested_late_minutes' => $lateMinutes,
                'status' => 'pending',
                'resolved_by' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

        $just = LateJustification::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('date', $date)->first();

        if ($just && $just->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'Tu justificante ya fue aprobado.',
                'justification' => $just,
            ], 201);
        }

        $this->notifications->sendToTenantAdmins(
            $tenantId,
            'Justificante de retardo',
            "{$user->name} envió un justificante por su retardo de hoy.",
            ['type' => 'late_justification_request']
        );

        return response()->json([
            'success' => true,
            'message' => 'Justificante enviado. Un administrador lo revisará.',
            'justification' => $just,
        ], 201);
    }

    /** Lista de justificantes PENDIENTES del tenant (para el panel del admin). */
    public function pending()
    {
        $tenantId = Auth::user()->tenant_id;
        if ($tenantId === null) {
            // Guard explícito (mismo criterio que request()/R80/R81): sin tenant no hay contexto.
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // El retardo REAL del día (recalculado del ponche), no el congelado al solicitar: si el
        // empleado solicitó ANTES de fichar (preventivo), `requested_late_minutes` sería null y el
        // admin aprobaría a ciegas. Se toma el MAX por si (teóricamente) hubiera más de un tarde.
        $lateByDay = DB::table('time_entries')
            ->select('user_id', 'date', DB::raw('MAX(late_minutes) as actual_late'))
            ->where('tenant_id', $tenantId)
            ->where('type', 'check_in')
            ->where('is_late', true)
            ->groupBy('user_id', 'date');

        $rows = DB::table('late_justifications as j')
            ->leftJoin('employees as e', function ($join) {
                $join->on('e.user_id', '=', 'j.user_id')->on('e.tenant_id', '=', 'j.tenant_id');
            })
            ->leftJoinSub($lateByDay, 'te', function ($join) {
                $join->on('te.user_id', '=', 'j.user_id')->on('te.date', '=', 'j.date');
            })
            ->where('j.tenant_id', $tenantId)
            ->where('j.status', 'pending')
            ->orderBy('j.created_at', 'asc')
            ->get([
                'j.id', 'j.user_id', 'j.date', 'j.reason', 'j.status', 'j.created_at',
                'e.name as employee_name',
                DB::raw('COALESCE(te.actual_late, j.requested_late_minutes) as requested_late_minutes'),
            ]);

        return response()->json($rows);
    }

    /** El admin aprueba o rechaza. R75: nadie resuelve su propio justificante. */
    public function resolve(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:approved,rejected']);

        $admin = Auth::user();
        $tenantId = $admin->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $result = DB::transaction(function () use ($id, $tenantId, $admin, $request) {
            $just = LateJustification::withoutGlobalScopes()
                ->where('id', $id)->where('tenant_id', $tenantId)->lockForUpdate()->first();

            if (!$just) {
                return ['error' => 'Justificante no encontrado en su empresa.', 'code' => 404];
            }
            if ($just->status !== 'pending') {
                return ['error' => 'El justificante ya fue resuelto.', 'code' => 422];
            }
            // R75: el que revisa no puede ser el que lo pidió — si no, el control no controla nada.
            if ((int) $just->user_id === (int) $admin->id) {
                return ['error' => 'No puedes resolver tu propio justificante. Debe hacerlo otro administrador o supervisor.', 'code' => 403];
            }

            $just->update([
                'status' => $request->input('status'),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            return ['justification' => $just];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], $result['code']);
        }

        $just = $result['justification'];
        $aprobado = $just->status === 'approved';

        // Rastro de auditoría PERSISTENTE de un acto que MUEVE DINERO (perdona una deducción), igual
        // que R81. El único registro antes era la fila mutable (que un re-solicitar reabre); esto deja
        // constancia append-only de quién resolvió y cómo, para una disputa de nómina posterior.
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => (int) $just->user_id,
            'date' => Carbon::parse($just->date)->format('Y-m-d'),
            'type' => $aprobado ? 'late_justification_approved' : 'late_justification_rejected',
            'timestamp_str' => now()->format('H:i:s'),
            'reason' => ($aprobado ? 'Justificante APROBADO' : 'Justificante RECHAZADO') . ' por ' . $admin->name,
            'details' => $aprobado
                ? 'El retardo de ese día no se descontará en nómina.'
                : 'El retardo de ese día se mantiene.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifications->sendToUser(
            (int) $just->user_id,
            $aprobado ? 'Justificante aprobado' : 'Justificante rechazado',
            $aprobado
                ? 'Tu justificante fue aprobado; el retardo de ese día no se descontará.'
                : 'Tu justificante fue rechazado.',
            ['type' => 'late_justification', 'status' => $just->status]
        );

        return response()->json([
            'success' => true,
            'message' => $aprobado ? 'Justificante aprobado. El retardo no se descontará.' : 'Justificante rechazado.',
            'justification' => $just,
        ]);
    }
}
