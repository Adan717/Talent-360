<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use Illuminate\Console\Command;

class PurgeClockPhotos extends Command
{
    /**
     * §67.D — Política de retención de fotos de fichaje: 90 días, igual que la evidencia de
     * comedor (§23). Las fotos son datos personales sensibles y la Landing promete "Derechos
     * ARCO & Biométricos". Borra el archivo físico y limpia photo_url (la FILA del fichaje se
     * conserva: es un registro de asistencia con valor legal/de nómina; solo se purga la foto).
     */
    protected $signature = 'clock-photos:purge {--days=90 : Purga fotos de fichajes con más de N días}';

    protected $description = 'Purga las fotos de fichaje más antiguas que N días (archivo + campo photo_url), conservando el registro.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateString();

        $old = TimeEntry::withoutGlobalScopes()
            ->whereNotNull('photo_url')
            ->where('date', '<', $cutoff)
            ->get(['id', 'photo_url']);

        // Sólo se borra DENTRO de la carpeta de fotos de fichaje, comprobado con realpath.
        //
        // Este comando corre solo cada día a las 03:15 y antes hacía
        // `@unlink(public_path(ltrim($photo_url, '/')))` con el `photo_url` que hubiera
        // guardado el cliente en `details` (§67). Un `"../.env"` resolvía a /var/www/.env:
        // cualquier colaborador con sesión podía dejar programado el borrado del .env del
        // servidor —o de un expediente del storage privado— para cuando venciera la
        // retención. La entrada ya se filtra en TimeEntryController::punch; esto es el
        // segundo cerrojo, por las filas que pudieran venir de antes.
        $carpetaPermitida = realpath(public_path('uploads/clock-photos'));

        $deletedFiles = 0;
        $sospechosos = 0;
        foreach ($old as $entry) {
            if (!$entry->photo_url) {
                continue;
            }

            $path = realpath(public_path(ltrim($entry->photo_url, '/')));

            if ($path === false) {
                continue; // no existe: nada que borrar
            }

            if (!$carpetaPermitida || !str_starts_with($path, $carpetaPermitida . DIRECTORY_SEPARATOR)) {
                $sospechosos++;
                $this->warn("Ruta fuera de la carpeta de fotos, NO se borra: {$entry->photo_url} (fichaje {$entry->id})");
                continue;
            }

            @unlink($path);
            $deletedFiles++;
        }

        $cleared = TimeEntry::withoutGlobalScopes()
            ->whereNotNull('photo_url')
            ->where('date', '<', $cutoff)
            ->update(['photo_url' => null]);

        $this->info("Purgadas {$cleared} fotos de fichaje y {$deletedFiles} archivos anteriores a {$cutoff}.");

        if ($sospechosos > 0) {
            $this->error("{$sospechosos} referencia(s) apuntaban FUERA de la carpeta de fotos y se ignoraron. "
                . 'Eso no lo produce la aplicación: revísalas en time_entries.photo_url.');
        }

        return self::SUCCESS;
    }
}
