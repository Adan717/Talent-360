<?php

namespace App\Services;

use App\Models\SimulatorSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SimulatorSessionService
{
    private const PURGE_TABLES = ['time_entries', 'store_logs', 'contingencies', 'internal_messages', 'audit_logs'];

    /**
     * Busca la sesión activa del tenant; si no existe, crea una nueva.
     */
    public function getActiveSession(int $tenantId, ?int $userId = null): SimulatorSession
    {
        $active = SimulatorSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        return $active ?: $this->createSession($tenantId, $userId);
    }

    /**
     * Cierra la sesión activa (si existe) y crea una nueva con simulated_date + 1 día.
     * Es la acción del botón "Iniciar Nueva Sesión" — nunca borra datos.
     */
    public function startNewSession(int $tenantId, ?int $userId = null): SimulatorSession
    {
        SimulatorSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->update(['status' => 'closed']);

        return $this->createSession($tenantId, $userId);
    }

    private function createSession(int $tenantId, ?int $userId = null): SimulatorSession
    {
        $lastSession = SimulatorSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        $simulatedDate = $lastSession
            ? Carbon::parse($lastSession->simulated_date)->addDay()
            : Carbon::now();

        return SimulatorSession::create([
            'tenant_id' => $tenantId,
            'started_by_user_id' => $userId,
            'simulated_date' => $simulatedDate->format('Y-m-d'),
            'status' => 'active',
        ]);
    }

    /**
     * Purga los datos (time_entries, store_logs, contingencies, internal_messages,
     * audit_logs) de una sesión específica, o de TODAS las sesiones del tenant si no
     * se especifica $sessionId. Por construcción nunca toca filas con
     * simulation_session_id NULL (datos reales) — es el reemplazo seguro del
     * TRUNCATE sin filtrar que tenía ClockController::resetDb.
     */
    public function purge(int $tenantId, ?int $sessionId = null): array
    {
        $query = SimulatorSession::withoutGlobalScopes()->where('tenant_id', $tenantId);
        if ($sessionId) {
            $query->where('id', $sessionId);
        }
        $sessionIds = $query->pluck('id')->all();

        if (empty($sessionIds)) {
            return ['purged_sessions' => 0, 'deleted_rows' => 0];
        }

        $deletedRows = 0;
        foreach (self::PURGE_TABLES as $table) {
            $deletedRows += DB::table($table)->whereIn('simulation_session_id', $sessionIds)->delete();
        }

        return ['purged_sessions' => count($sessionIds), 'deleted_rows' => $deletedRows];
    }
}
