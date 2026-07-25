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
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : Carbon::today()->toDateString();

        // Asignaciones aún abiertas (en progreso o en pausa) de un día ANTERIOR a hoy.
        $count = TaskAssignment::withoutGlobalScopes()
            ->whereIn('status', ['in_progress', 'paused'])
            ->whereNotNull('date')
            ->whereDate('date', '<', $today)
            ->update([
                'status' => 'awaiting_validation',
                'flagged_incomplete' => true,
                'updated_at' => now(),
            ]);

        $this->info("Tareas inconclusas marcadas para validación gerencial: {$count}");
        Log::info('FlagUnfinishedTasksCommand', ['flagged' => $count, 'before_date' => $today]);

        return self::SUCCESS;
    }
}
