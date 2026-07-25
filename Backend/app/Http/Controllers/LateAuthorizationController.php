<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Models\LateAuthorizationRequest;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tolerancia con solicitud de autorización (Ronda 56).
 *
 * El Retardo Extremo (R14) bloquea la entrada; aquí el empleado pide autorización y un admin la
 * resuelve. Una fila `approved` para (tenant, user, día) levanta el bloqueo en ClockService.
 */
class LateAuthorizationController extends Controller
{
    protected $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * El empleado solicita autorización para su retardo de HOY (idempotente por día).
     */
    public function request(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $timezone = TenantTimezone::for($tenantId);
        $now = Carbon::now($timezone);
        $date = $now->format('Y-m-d');

        // Retardo calculado SERVER-SIDE (contexto para el admin), no confiado al cliente. Mismo
        // criterio que ClockService: el turno vive en employees.shiftStart, no en users (R32).
        $shiftStart = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->value('shiftStart') ?? '09:00:00';
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', "$date $shiftStart", $timezone);
        $lateMinutes = $now->greaterThan($expected) ? (int) abs($now->diffInMinutes($expected)) : 0;

        // Idempotente por (tenant, user, día), y race-safe SIN read-then-write:
        //  1. insertOrIgnore asegura la fila sin reventar por el unique ante dos solicitudes
        //     concurrentes (evita el 500; el perdedor no crea nada).
        //  2. El "reabrir a pending" es un UPDATE CONDICIONAL `status != approved`, atómico: si el
        //     admin aprueba en paralelo, este update NO pisa la aprobación (el bug TOCTOU sería
        //     reabrir un approved a pending y perder resolved_by/at). Una solicitud rechazada sí se
        //     reabre (el empleado insiste).
        DB::table('late_authorization_requests')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'date' => $date,
            'requested_late_minutes' => $lateMinutes,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('late_authorization_requests')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', '!=', 'approved')
            ->update([
                'requested_late_minutes' => $lateMinutes,
                'status' => 'pending',
                'resolved_by' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

        $req = LateAuthorizationRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        // Si ya estaba aprobada, no se re-notifica ni se reabre: el empleado ya puede entrar.
        if ($req && $req->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'Tu autorización ya fue aprobada. Puedes registrar tu entrada.',
                'request' => $req,
            ], 201);
        }

        $this->notifyTenantAdmins($tenantId, $user->name, $lateMinutes);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud enviada. Un administrador debe autorizar tu entrada.',
            'request' => $req,
        ], 201);
    }

    /**
     * Lista de solicitudes PENDIENTES del tenant (para el panel del admin).
     */
    public function pending()
    {
        $tenantId = Auth::user()->tenant_id;

        $rows = DB::table('late_authorization_requests as r')
            ->leftJoin('employees as e', function ($join) {
                $join->on('e.user_id', '=', 'r.user_id')->on('e.tenant_id', '=', 'r.tenant_id');
            })
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', 'pending')
            ->orderBy('r.created_at', 'asc')
            ->get([
                'r.id', 'r.user_id', 'r.date', 'r.requested_late_minutes', 'r.status', 'r.created_at',
                'e.name as employee_name',
            ]);

        return response()->json($rows);
    }

    /**
     * El admin aprueba o rechaza una solicitud.
     */
    public function resolve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $admin = Auth::user();
        $tenantId = $admin->tenant_id;

        $result = DB::transaction(function () use ($id, $tenantId, $admin, $request) {
            // Scope por tenant + lock: no se resuelve la solicitud de otro tenant, y se evita la
            // doble-resolución concurrente.
            $req = LateAuthorizationRequest::withoutGlobalScopes()
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (!$req) {
                return ['error' => 'Solicitud no encontrada en su empresa.', 'code' => 404];
            }
            if ($req->status !== 'pending') {
                return ['error' => 'La solicitud ya fue resuelta.', 'code' => 422];
            }

            // R75: NADIE resuelve su PROPIA solicitud. El control existe para que decida alguien MÁS
            // sobre una llegada extremadamente tarde; si el admin que llega tarde se autoriza a sí
            // mismo, el control no controla nada (cazado probando en vivo: la solicitud del admin le
            // llegaba a él mismo y se la aprobaba).
            //
            // No deja varado a nadie: `/clock/punch` y `/sync/clock` fijan `supervisor_override` según
            // el ROL del emisor, así que a un admin/supervisor el bloqueo de Retardo Extremo ni
            // siquiera le aplica cuando ficha desde la app. Sólo el kiosko lo niega a propósito
            // (KioskController), y ahí siempre queda fichar desde la app.
            if ((int) $req->user_id === (int) $admin->id) {
                return [
                    'error' => 'No puedes resolver tu propia solicitud. Debe autorizarla otro administrador o supervisor.',
                    'code' => 403,
                ];
            }

            $req->update([
                'status' => $request->input('status'),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            return ['request' => $req];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], $result['code']);
        }

        $req = $result['request'];

        // Aviso al empleado (best-effort; el reloj también refresca por su cuenta).
        $aprobada = $req->status === 'approved';
        $this->notifications->sendToUser(
            (int) $req->user_id,
            $aprobada ? 'Entrada autorizada' : 'Solicitud rechazada',
            $aprobada
                ? 'Tu administrador autorizó tu entrada. Ya puedes registrar tu asistencia.'
                : 'Tu solicitud de autorización de entrada fue rechazada.',
            ['type' => 'late_authorization', 'status' => $req->status]
        );

        return response()->json([
            'success' => true,
            'message' => $aprobada ? 'Autorización concedida.' : 'Solicitud rechazada.',
            'request' => $req,
        ]);
    }

    /**
     * Notifica a los admin/supervisor DEL TENANT vía el helper compartido tenant-scoped (R80; antes
     * este loop vivía duplicado aquí y en PanicController).
     */
    private function notifyTenantAdmins(int $tenantId, string $employeeName, int $lateMinutes): void
    {
        $this->notifications->sendToTenantAdmins(
            $tenantId,
            'Solicitud de autorización de entrada',
            "{$employeeName} pide autorización para registrar su entrada (retardo de {$lateMinutes} min).",
            ['type' => 'late_authorization_request']
        );
    }
}
