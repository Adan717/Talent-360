<?php

namespace App\Console\Commands;

use App\Models\MealPhotoEvidence;
use Illuminate\Console\Command;

class PurgeMealPhotoEvidence extends Command
{
    /**
     * Política de retención (§23 del contrato, decidida por Backend a falta de una
     * preferencia explícita): 90 días. Borra el archivo físico y la fila. No se
     * programa sola — agréguenla al scheduler (bootstrap/app.php withSchedule, o cron)
     * con la periodicidad que prefieran, ej. diaria.
     */
    protected $signature = 'meal-evidence:purge {--days=90 : Borra evidencia con más de N días de antigüedad}';

    protected $description = 'Purga evidencia fotográfica de comedor más antigua que N días (archivo + registro).';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateString();

        $old = MealPhotoEvidence::withoutGlobalScopes()->where('date', '<', $cutoff)->get();

        $deletedFiles = 0;
        foreach ($old as $evidence) {
            if ($evidence->path && file_exists($evidence->path)) {
                @unlink($evidence->path);
                $deletedFiles++;
            }
        }

        $deletedRows = MealPhotoEvidence::withoutGlobalScopes()->where('date', '<', $cutoff)->delete();

        $this->info("Purgados {$deletedRows} registros y {$deletedFiles} archivos anteriores a {$cutoff}.");

        return self::SUCCESS;
    }
}
