<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ClockService;
use App\Helpers\TenantTimezone;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('shifts:close-orphans')]
#[Description('Cierra automáticamente los turnos huérfanos (jornada activa sin check_out) tras la hora de cierre de la sucursal y registra una alerta en audit_logs para auditar posible fraude de nómina.')]
class CloseOrphanShifts extends Command
{
    public function handle(): int
    {
        $totalClosed = 0;

        foreach (Tenant::where('is_active', true)->get() as $tenant) {
            // La fecha y la hora se calculan en la ZONA HORARIA del tenant (los ponches
            // se fechan en esa tz), no en UTC del servidor. Sin esto, el barrido correría
            // antes del cierre local y cerraría turnos legítimos.
            $tz = $this->timezoneFor($tenant->id);
            $localNow = Carbon::now($tz);
            $today = $localNow->format('Y-m-d');
            $nowHm = $localNow->format('H:i');

            $closeTime = $this->closeTimeFor($tenant->id);

            // Guard: no cerrar antes de que la tienda cierre (comparación en tz local;
            // substr(0,5) normaliza por si closeTime trae segundos).
            if ($nowHm < substr($closeTime, 0, 5)) {
                continue;
            }

            // La jornada de AYER también: un turno que cruza medianoche (22:00→02:00) guarda su
            // check_in bajo la fecha de negocio del día que EMPEZÓ, así que el huérfano del velador
            // no aparecía nunca en el barrido de hoy y quedaba abierto para siempre (2026-08-22).
            // Se hace aquí, pasada ya la hora de cierre de HOY: para entonces cualquier jornada de
            // ayer terminó con seguridad, así que esto no puede cerrar un turno vivo.
            $totalClosed += $this->closeOrphansForTenant($tenant->id, $localNow->copy()->subDay()->format('Y-m-d'), $closeTime);
            $totalClosed += $this->closeOrphansForTenant($tenant->id, $today, $closeTime);
        }

        $this->info("Turnos huérfanos cerrados: {$totalClosed}");

        return self::SUCCESS;
    }

    /**
     * Zona horaria del tenant (system_settings.timezone), default America/Mexico_City.
     * Delega en el resolver compartido App\Helpers\TenantTimezone (que además valida la tz
     * contra DateTimeZone — antes esta copia devolvía una tz inválida que reventaba Carbon).
     */
    private function timezoneFor(int $tenantId): string
    {
        return TenantTimezone::for($tenantId);
    }

    /**
     * Hora de cierre de la sucursal (system_settings.storeSchedule.closeTime),
     * default '18:00'. Mismo patrón que StoreOpeningService.
     */
    private function closeTimeFor(int $tenantId): string
    {
        $row = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'storeSchedule')
            ->first();

        if ($row) {
            $val = json_decode($row->value, true);
            if (!empty($val['closeTime'])) {
                return $val['closeTime'];
            }
        }

        return '18:00';
    }

    /**
     * Cierra los turnos huérfanos del día para un tenant. Devuelve cuántos cerró.
     */
    private function closeOrphansForTenant(int $tenantId, string $today, string $closeTime): int
    {
        // Registros de asistencia del día (se excluyen los auxiliares como reservas de
        // comida), en orden cronológico (id asc).
        $entries = DB::table('time_entries')
            ->where('tenant_id', $tenantId)
            ->where('date', $today)
            ->whereNotIn('type', ClockService::AUXILIARY_ENTRY_TYPES)
            // (2026-08-22) Sólo asistencia REAL. Sin este filtro, un check_in que quedó abierto en
            // una sesión del Simulador Matrix se tomaba por un turno huérfano de verdad: se le
            // escribía al colaborador un check_out real (simulation_session_id NULL, es decir
            // contaminación permanente de su asistencia) y una alerta acusándolo de posible fraude.
            ->whereNull('simulation_session_id')
            ->orderBy('id')
            ->get(['user_id', 'type', 'time']);

        // Un turno es huérfano si el ÚLTIMO registro del usuario NO es check_out y sí
        // hubo un check_in (maneja turnos re-abiertos: check_in→check_out→check_in).
        $latestType = [];
        $hasCheckIn = [];
        $ultimaActividad = [];
        foreach ($entries as $e) {
            $latestType[$e->user_id] = $e->type;
            if ($e->type === 'check_in') {
                $hasCheckIn[$e->user_id] = true;
            }
            $hora = substr((string) $e->time, 0, 8);
            if (!isset($ultimaActividad[$e->user_id]) || $hora > $ultimaActividad[$e->user_id]) {
                $ultimaActividad[$e->user_id] = $hora;
            }
        }

        $orphans = [];
        foreach ($latestType as $userId => $type) {
            if (!empty($hasCheckIn[$userId]) && $type !== 'check_out') {
                $orphans[] = $userId;
            }
        }

        if (empty($orphans)) {
            return 0;
        }

        $closeTimeHis = strlen($closeTime) === 5 ? $closeTime . ':00' : $closeTime;
        $now = now();

        foreach ($orphans as $userId) {
            // La salida se estampa a la hora de cierre de la sucursal, pero NUNCA antes del
            // último ponche real de la persona: con la tienda cerrando a las 18:00, quien entró
            // a las 19:00 por un inventario recibía un check_out de las 18:00 — una salida ANTERIOR
            // a su entrada, que en el reporte de horas da 0 minutos trabajados (2026-08-22).
            $horaSalida = max($closeTimeHis, $ultimaActividad[$userId] ?? $closeTimeHis);

            // Check_out automático a la hora de cierre. Insert directo, NO vía
            // processPunch: es un cierre forzado del sistema que no debe pasar por reglas
            // de festivo/tienda-cerrada/tolerancia.
            DB::table('time_entries')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'date' => $today,
                'type' => 'check_out',
                'time' => $horaSalida,
                'is_late' => false,
                'late_minutes' => 0,
                'details' => json_encode([
                    'auto_closed' => true,
                    'reason' => 'orphan_shift',
                    'note' => 'Cierre automático por turno huérfano (olvidó checar salida).',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Alerta 🔴 para auditoría de nómina.
            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'date' => $today,
                'type' => 'orphan_shift',
                'timestamp_str' => "$today $horaSalida",
                'reason' => "🔴 Turno huérfano: cierre automático a las " . substr($horaSalida, 0, 5) . " por falta de checada de salida. Auditar posible fraude de nómina.",
                'punishment_amount' => 0,
                'details' => json_encode(['auto_closed' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        event(new \App\Events\MonitorUpdated($tenantId));

        return count($orphans);
    }
}
