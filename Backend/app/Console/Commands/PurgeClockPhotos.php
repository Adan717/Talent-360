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

        $deletedFiles = 0;
        foreach ($old as $entry) {
            $path = public_path(ltrim($entry->photo_url, '/'));
            if ($entry->photo_url && file_exists($path)) {
                @unlink($path);
                $deletedFiles++;
            }
        }

        $cleared = TimeEntry::withoutGlobalScopes()
            ->whereNotNull('photo_url')
            ->where('date', '<', $cutoff)
            ->update(['photo_url' => null]);

        $this->info("Purgadas {$cleared} fotos de fichaje y {$deletedFiles} archivos anteriores a {$cutoff}.");

        return self::SUCCESS;
    }
}
