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

        // El fin de turno de CADA colaborador manda sobre la hora de cierre de la tienda: es la
        // hora a la que esa persona debía irse. La tienda es sólo el respaldo si no tiene turno.
        $finDeTurno = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $orphans)
            ->pluck('shiftEnd', 'user_id');

        // ¿Se fue a comer y nunca fichó el regreso? Se cierra también esa comida, pero SIN
        // inventarle minutos de exceso: no hay dato para calcularlos (decisión 2026-08-22).
        $comidaAbierta = [];
        foreach ($entries as $e) {
            if ($e->type === 'meal_start') {
                $comidaAbierta[$e->user_id] = true;
            } elseif ($e->type === 'meal_end') {
                unset($comidaAbierta[$e->user_id]);
            }
        }

        foreach ($orphans as $userId) {
            // LA HORA QUE SE ESTAMPA (reescrito 2026-08-22 tras verlo en datos reales).
            //
            // Antes era `max(cierre de tienda, último ponche)`, y con un turno cuyo horario NO
            // explicaba la jornada eso producía una salida en el MISMO SEGUNDO de la entrada:
            // jornadas de CERO minutos escritas sobre asistencia real (María, 21-ago: entrada
            // 20:33:05, salida sintética 20:33:05).
            //
            // Regla actual: se usa el fin de turno de la persona. Si tiene ponches DESPUÉS de esa
            // hora, su horario no explica el día — típico del error a.m./p.m. que arrastran los
            // horarios de esta empresa — y entonces NO se inventa nada: sólo queda la alerta 🔴
            // para que un humano lo resuelva. El sistema adivina o avisa, nunca las dos cosas.
            $base = (string) ($finDeTurno[$userId] ?? '');
            $base = $base !== '' ? (strlen($base) === 5 ? $base . ':00' : substr($base, 0, 8)) : $closeTimeHis;
            $ultima = $ultimaActividad[$userId] ?? null;

            if ($ultima !== null && $ultima > $base) {
                DB::table('audit_logs')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'date' => $today,
                    'type' => 'orphan_shift',
                    'timestamp_str' => "$today $ultima",
                    'reason' => '🔴 Turno huérfano SIN cerrar: el horario programado (fin ' . substr($base, 0, 5)
                        . ') no explica la jornada — hay actividad a las ' . substr($ultima, 0, 5)
                        . '. Revísalo a mano: el sistema no inventa la hora de salida.',
                    'punishment_amount' => 0,
                    'details' => json_encode(['auto_closed' => false, 'revision_manual' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $horaSalida = $base;

            // Comida sin regreso: se cierra a la misma hora, marcada y con exceso CERO.
            if (!empty($comidaAbierta[$userId])) {
                DB::table('time_entries')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'date' => $today,
                    'type' => 'meal_end',
                    'time' => $horaSalida,
                    'is_late' => false,
                    'late_minutes' => 0,
                    'details' => json_encode([
                        'auto_closed' => true,
                        'reason' => 'meal_sin_regreso',
                        'note' => 'Cierre automático: se fue a comer y no fichó el regreso. No se le calculan minutos de exceso porque no hay dato para calcularlos.',
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

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
