<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\TaskAssignment;
use Carbon\Carbon;

/**
 * Sección 2 #2: comando nocturno (00:30) que detecta tareas dejadas inconclusas de
 * días anteriores (quedaron en 'in_progress'/'paused' y nunca se cerraron — típico
 * de un celular que se apagó o una jornada no cerrada) y las pasa a
 * 'awaiting_validation' con la bandera flagged_incomplete, para que el gerente al día
 * siguiente decida con los 3 botones. El bono NO se pierde ni se paga aquí: queda
 * congelado esperando la decisión.
 *
 * Uso manual: php artisan tasks:flag-unfinished [--date=YYYY-MM-DD]
 */
class FlagUnfinishedTasksCommand extends Command
{
    protected $signature = 'tasks:flag-unfinished {--date= : Considera inconclusas las de fecha anterior a esta (default: hoy)}';

    protected $description = 'Marca las tareas inconclusas de días anteriores como pendientes de validación gerencial';

    public function handle(): int
    {
        $override = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : null;

        // A5 (auditoría 2026-07-27): el corte era `date < today()` del SERVIDOR (UTC en
        // Hetzner). A las 00:30 UTC del día D+1 en México todavía es D a las 18:30 con
        // turnos ABIERTOS — el barrido global flaggeaba el turno EN CURSO a media jornada.
        // El corte ahora es POR TENANT con su zona horaria de negocio (TenantTimezone,
        // el mismo criterio que ya usa el Reloj para todo lo fechado). El --date manual
        // sigue mandando parejo para todos (uso de reproceso).
        $tenantIds = \Illuminate\Support\Facades\DB::table('tenants')
            ->where('is_active', true)
            ->pluck('id');

        $count = 0;
        foreach ($tenantIds as $tenantId) {
            $today = $override ?? Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString();

            // Asignaciones aún abiertas (en progreso o en pausa) de un día ANTERIOR al hoy
            // operativo de SU tenant.
            $count += TaskAssignment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['in_progress', 'paused'])
                ->whereNotNull('date')
                ->whereDate('date', '<', $today)
                ->update([
                    'status' => 'awaiting_validation',
                    'flagged_incomplete' => true,
                    'updated_at' => now(),
                ]);
        }

        $this->info("Tareas inconclusas marcadas para validación gerencial: {$count}");
        Log::info('FlagUnfinishedTasksCommand', ['flagged' => $count, 'override_date' => $override]);

        return self::SUCCESS;
    }
}
