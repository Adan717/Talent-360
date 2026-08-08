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
            if (!$evidence->path) {
                continue;
            }

            // Desde 2026-08-08 `path` es una ruta RELATIVA del disco privado. Se mantiene el
            // camino absoluto para las filas viejas que aún no pasaron por `meal-evidence:privatizar`:
            // si la purga no las reconoce, borra la fila y deja el archivo huérfano — que es
            // justo como quedó un biométrico público en el servidor sin registro que lo nombrara.
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($evidence->path)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($evidence->path);
                $deletedFiles++;
            } elseif (file_exists($evidence->path)) {
                @unlink($evidence->path);
                $deletedFiles++;
            }
        }

        $deletedRows = MealPhotoEvidence::withoutGlobalScopes()->where('date', '<', $cutoff)->delete();

        $this->info("Purgados {$deletedRows} registros y {$deletedFiles} archivos anteriores a {$cutoff}.");

        return self::SUCCESS;
    }
}
