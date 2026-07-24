<?php

namespace App\Http\Controllers;

use App\Models\PanicIncident;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Botón de Pánico REAL (Ronda 80, Fase 1 / T1.1 del plan v2).
 *
 * Antes `triggerPanic` sólo hacía `addMatrixEvent` en el FE: confirmaba una emergencia sin registrar
 * nada ni avisar a nadie fuera de esa pantalla (uno de los "teatros" del dial). Aquí el incidente se
 * PERSISTE (categoría + geo) y se ALERTA a los admin/supervisor DEL TENANT (helper tenant-scoped,
 * R69/R80). El overlay de bloqueo del reloj es UX del cliente; la verdad del incidente vive en BD.
 *
 * Atribución: el reportante es SIEMPRE Auth::user() (no un user_id del cliente) — aceptar el id del
 * payload dejaría a cualquier empleado reportar a nombre de otro. El kiosko compartido no usa este
 * flujo (KioskScreen no monta useClockEngine, R55), así que sesión === persona en todos los caminos
 * de producción.
 */
class PanicController extends Controller
{
    /** Las 4 emergencias del spec (§5). El label humano canónico vive aquí; el FE lo espeja. */
    private const CATEGORIES = [
        'robo_asalto' => 'Robo / Asalto',
        'incendio' => 'Incendio',
        'emergencia_medica' => 'Emergencia Médica',
        'fallo_energia' => 'Fallo General de Energía',
    ];

    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * El empleado declara una emergencia. El tenant sale del usuario autenticado (no del cliente).
     */
    public function report(Request $request)
    {
        $request->validate([
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $user = Auth::user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $incident = PanicIncident::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'status' => 'active',
        ]);

        // El fan-out FCM va DESPUÉS de responder: es serial, síncrono y sin timeout HTTP propio, y
        // éste es el endpoint más crítico en latencia de la app — con FCM colgado, el 201 tardaría
        // N mandos × round-trip y el axios del cliente expiraría ("no se pudo alertar" falso +
        // reporte duplicado). El incidente ya está persistido; la alerta es best-effort inmediato.
        $notifications = $this->notifications;
        $label = self::CATEGORIES[$incident->category];
        $reporterName = $user->name;
        app()->terminating(function () use ($notifications, $tenantId, $reporterName, $incident, $label) {
            $notifications->sendToTenantAdmins(
                $tenantId,
                "🚨 Botón de Pánico: {$label}",
                "{$reporterName} declaró una emergencia ({$label}) en la sucursal.",
                [
                    'type' => 'panic_incident',
                    'incident_id' => (string) $incident->id,
                    'category' => $incident->category,
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Emergencia registrada. Se alertó a administración.',
            'incident' => $incident,
        ], 201);
    }

    /**
     * Incidentes ACTIVOS del tenant (para el panel del admin/supervisor).
     */
    public function active()
    {
        $tenantId = Auth::user()->tenant_id;
        if ($tenantId === null) {
            // Mismo criterio que report(): sin tenant no hay contexto — 403 explícito, no una lista
            // vacía que haga parecer que "no pasa nada" mientras hay incidentes ardiendo.
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // El nombre sale del expediente y CAE al users.name: un mando (admin/supervisor) puede
        // reportar sin tener fila en employees, y un incidente anónimo en el panel es inservible.
        $rows = DB::table('panic_incidents as p')
            ->leftJoin('employees as e', function ($join) {
                $join->on('e.user_id', '=', 'p.user_id')->on('e.tenant_id', '=', 'p.tenant_id');
            })
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.tenant_id', $tenantId)
            ->where('p.status', 'active')
            ->orderBy('p.created_at', 'desc')
            ->get([
                'p.id', 'p.user_id', 'p.category', 'p.description',
                'p.latitude', 'p.longitude', 'p.status', 'p.created_at',
                DB::raw('COALESCE(e.name, u.name) as employee_name'),
            ]);

        return response()->json($rows);
    }

    /**
     * El incidente ACTIVO del propio usuario (o null). Rehidratación del reloj tras un reload: el
     * overlay de pánico no puede vivir sólo en la memoria de React (R80, review).
     */
    public function mine()
    {
        $user = Auth::user();

        $incident = PanicIncident::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json(['incident' => $incident]);
    }

    /**
     * El reportante desactiva SU pánico: resuelve TODOS sus incidentes activos, sin id. Así el
     * cliente no necesita contabilidad de ids (que un reload, una carrera con el POST en vuelo o un
     * doble-tap vuelven inconsistente — review R80): el servidor sabe cuáles son los suyos.
     */
    public function resolveMine()
    {
        $user = Auth::user();

        $count = PanicIncident::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->update([
                'status' => 'resolved',
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => $count > 0 ? 'Modo pánico desactivado.' : 'No tenías incidentes activos.',
            'resolved_count' => $count,
        ]);
    }

    /**
     * Resolución POR ID (para el panel del mando). Lo puede hacer el REPORTANTE o un mando del
     * tenant (admin/supervisor). Un tercero ajeno NO — evita que cualquier empleado silencie la
     * emergencia de otro. (platform_admin no entra: no tiene tenant_id y este flujo es por-tenant;
     * su soporte a tenants sin mandos alcanzables sería un flujo aparte.)
     */
    public function resolve(Request $request, $id)
    {
        $actor = Auth::user();
        $tenantId = $actor->tenant_id;

        $result = DB::transaction(function () use ($id, $tenantId, $actor) {
            // Scope por tenant + lock: no se resuelve el incidente de otro tenant, y se evita la
            // doble-resolución concurrente (doble toque / dos mandos a la vez).
            $incident = PanicIncident::withoutGlobalScopes()
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (!$incident) {
                return ['error' => 'Incidente no encontrado en su empresa.', 'code' => 404];
            }

            $esMando = in_array($actor->role, ['admin', 'supervisor'], true);
            $esReportante = (int) $incident->user_id === (int) $actor->id;
            if (!$esMando && !$esReportante) {
                return ['error' => 'No autorizado para resolver este incidente.', 'code' => 403];
            }

            if ($incident->status !== 'active') {
                return ['error' => 'El incidente ya fue resuelto.', 'code' => 422];
            }

            $incident->update([
                'status' => 'resolved',
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            return ['incident' => $incident];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], $result['code']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Modo pánico desactivado.',
            'incident' => $result['incident'],
        ]);
    }
}
