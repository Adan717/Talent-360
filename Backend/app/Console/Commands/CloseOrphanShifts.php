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
            ->orderBy('id')
            ->get(['user_id', 'type']);

        // Un turno es huérfano si el ÚLTIMO registro del usuario NO es check_out y sí
        // hubo un check_in (maneja turnos re-abiertos: check_in→check_out→check_in).
        $latestType = [];
        $hasCheckIn = [];
        foreach ($entries as $e) {
            $latestType[$e->user_id] = $e->type;
            if ($e->type === 'check_in') {
                $hasCheckIn[$e->user_id] = true;
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
            // Check_out automático a la hora de cierre. Insert directo, NO vía
            // processPunch: es un cierre forzado del sistema que no debe pasar por reglas
            // de festivo/tienda-cerrada/tolerancia.
            DB::table('time_entries')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'date' => $today,
                'type' => 'check_out',
                'time' => $closeTimeHis,
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
                'timestamp_str' => "$today $closeTimeHis",
                'reason' => "🔴 Turno huérfano: cierre automático a las {$closeTime} por falta de checada de salida. Auditar posible fraude de nómina.",
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
