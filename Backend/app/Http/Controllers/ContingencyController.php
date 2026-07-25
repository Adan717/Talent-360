<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Models\ContingencyDay;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Contingencia de jornada por fuerza mayor (Ronda 83, Fase 1 / T1.4 del plan v2 — CIERRA la Fase 1).
 *
 * El teatro que se cierra: declarar una eventualidad (sin luz / sin red / fuerza mayor) sólo se
 * registraba; la nómina no aplicaba efecto. Ahora el empleado declara la eventualidad de HOY, un admin
 * la aprueba, y una fila `approved` para (tenant, user, día) hace que ClockService pague ese día al
 * 100% (jornada causal, LFT Art. 42/427-431), sin contarlo como falta ni reducir el séptimo día, y
 * congele el retardo de ese día.
 *
 * Espejo estructural de LateJustificationController (R82): mismo declare/pending/resolve, misma
 * idempotencia race-safe, mismo R75, mismo audit_logs del acto que mueve dinero, misma cautela de
 * notificación tenant-scoped.
 */
class ContingencyController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /** El empleado declara la contingencia (fuerza mayor) de HOY (idempotente por día). */
    public function declare(Request $request)
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            // R101 (spec §4 paso 4): cuándo se declaró DE VERDAD, para declaraciones encoladas
            // offline que sincronizan después (p.ej. cruzando medianoche).
            'declared_at_client' => ['sometimes', 'nullable', 'date'],
        ]);

        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $timezone = TenantTimezone::for($tenantId);
        // R101: la FECHA de la contingencia sale del momento REAL de la declaración (tz del tenant).
        // Clamp anti-forjado: sólo hacia atrás (nunca futuro) y máximo 48h; fuera de eso, hoy.
        // El efecto de nómina sigue detrás del gate humano de R83 (un admin APRUEBA cada una).
        $momento = Carbon::now();
        $declaredAtClient = $request->input('declared_at_client');
        if ($declaredAtClient) {
            $candidato = Carbon::parse($declaredAtClient);
            if ($candidato->lessThan($momento) && $candidato->greaterThanOrEqualTo($momento->copy()->subHours(48))) {
                $momento = $candidato;
            }
        }
        $date = $momento->setTimezone($timezone)->format('Y-m-d');
        $reason = $request->input('reason');

        // Idempotente y race-safe (patrón R82): insertOrIgnore + reopen condicional `status != approved`.
        DB::table('contingency_days')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contingency_days')
            ->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('date', $date)
            ->where('status', '!=', 'approved')
            ->update([
                'reason' => $reason,
                'status' => 'pending',
                'resolved_by' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

        $cont = ContingencyDay::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $user->id)->where('date', $date)->first();

        if ($cont && $cont->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'Tu contingencia de hoy ya fue aprobada.',
                'contingency' => $cont,
            ], 201);
        }

        $this->notifications->sendToTenantAdmins(
            $tenantId,
            'Contingencia declarada (fuerza mayor)',
            "{$user->name} declaró una eventualidad (sin luz / fuerza mayor) hoy.",
            ['type' => 'contingency_declared']
        );

        return response()->json([
            'success' => true,
            'message' => 'Eventualidad declarada. Un administrador la revisará; si la aprueba, tu jornada se paga al 100%.',
            'contingency' => $cont,
        ], 201);
    }

    /** Lista de contingencias PENDIENTES del tenant (para el panel del admin). */
    public function pending()
    {
        $tenantId = Auth::user()->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $rows = DB::table('contingency_days as c')
            ->leftJoin('employees as e', function ($join) {
                $join->on('e.user_id', '=', 'c.user_id')->on('e.tenant_id', '=', 'c.tenant_id');
            })
            ->where('c.tenant_id', $tenantId)
            ->where('c.status', 'pending')
            ->orderBy('c.created_at', 'asc')
            ->get([
                'c.id', 'c.user_id', 'c.date', 'c.reason', 'c.status', 'c.created_at',
                'e.name as employee_name',
            ]);

        return response()->json($rows);
    }

    /** El admin aprueba o rechaza. R75: nadie resuelve su propia contingencia. */
    public function resolve(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:approved,rejected']);

        $admin = Auth::user();
        $tenantId = $admin->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $result = DB::transaction(function () use ($id, $tenantId, $admin, $request) {
            $cont = ContingencyDay::withoutGlobalScopes()
                ->where('id', $id)->where('tenant_id', $tenantId)->lockForUpdate()->first();

            if (!$cont) {
                return ['error' => 'Contingencia no encontrada en su empresa.', 'code' => 404];
            }
            if ($cont->status !== 'pending') {
                return ['error' => 'La contingencia ya fue resuelta.', 'code' => 422];
            }
            if ((int) $cont->user_id === (int) $admin->id) {
                return ['error' => 'No puedes resolver tu propia contingencia. Debe hacerlo otro administrador o supervisor.', 'code' => 403];
            }

            $cont->update([
                'status' => $request->input('status'),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            return ['contingency' => $cont];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], $result['code']);
        }

        $cont = $result['contingency'];
        $aprobado = $cont->status === 'approved';

        // Rastro de auditoría PERSISTENTE de un acto que MUEVE DINERO (paga un día no laborado al
        // 100%), igual que R81/R82.
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => (int) $cont->user_id,
            'date' => Carbon::parse($cont->date)->format('Y-m-d'),
            'type' => $aprobado ? 'contingency_approved' : 'contingency_rejected',
            'timestamp_str' => now()->format('H:i:s'),
            'reason' => ($aprobado ? 'Contingencia APROBADA' : 'Contingencia RECHAZADA') . ' por ' . $admin->name,
            'details' => $aprobado
                ? 'Jornada causal 100% pagada (LFT); no cuenta como falta y congela el retardo del día.'
                : 'La jornada de ese día se calcula normalmente.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifications->sendToUser(
            (int) $cont->user_id,
            $aprobado ? 'Contingencia aprobada' : 'Contingencia rechazada',
            $aprobado
                ? 'Tu eventualidad fue aprobada; ese día se te paga al 100% y no cuenta como falta.'
                : 'Tu eventualidad fue rechazada.',
            ['type' => 'contingency', 'status' => $cont->status]
        );

        return response()->json([
            'success' => true,
            'message' => $aprobado ? 'Contingencia aprobada. La jornada se paga al 100%.' : 'Contingencia rechazada.',
            'contingency' => $cont,
        ]);
    }
}
