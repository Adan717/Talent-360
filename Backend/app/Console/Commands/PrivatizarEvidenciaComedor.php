<?php

namespace App\Console\Commands;

use App\Models\MealPhotoEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saca de la carpeta pública la evidencia de comedor que ya estaba escrita (2026-08-08).
 *
 * El arreglo del controlador sólo cubre las fotos NUEVAS. Las que ya existían siguen en
 * `public/uploads/meal-evidence/...`, servidas como estáticos sin autenticación — se
 * comprobó en el servidor que respondían `200 image/jpeg` a cualquiera con la URL.
 *
 * Dos casos, y ninguno se borra:
 *  1. Con fila en la base: el archivo se mueve al disco privado y la fila se reapunta al
 *     endpoint autenticado.
 *  2. HUÉRFANO (archivo sin fila): también se mueve, a `meal-evidence/huerfanos/`. Pasa
 *     cuando la purga a 90 días borró el registro y dejó el archivo atrás; es el peor caso
 *     porque nadie sabe de quién es esa cara y nada la iba a limpiar nunca.
 *
 * Se mueve en vez de borrar a propósito: son datos personales, la decisión de destruirlos
 * es del dueño de la empresa, no de una migración.
 */
class PrivatizarEvidenciaComedor extends Command
{
    protected $signature = 'meal-evidence:privatizar {--dry-run : Sólo informa, no mueve nada}';

    protected $description = 'Mueve al disco privado la evidencia de comedor que quedó en la carpeta pública.';

    public function handle(): int
    {
        $simulacro = (bool) $this->option('dry-run');
        $movidos = 0;
        $huerfanos = 0;
        $noEncontrados = 0;

        // 1. Las que tienen fila.
        foreach (MealPhotoEvidence::withoutGlobalScopes()->get() as $evidencia) {
            // Ya privatizada (path relativo que existe en el disco privado): nada que hacer.
            if ($evidencia->path && Storage::disk('local')->exists($evidencia->path)) {
                continue;
            }

            $rutaPublica = $this->rutaPublicaDe($evidencia);
            if (!$rutaPublica || !file_exists($rutaPublica)) {
                $noEncontrados++;
                continue;
            }

            $extension = pathinfo($rutaPublica, PATHINFO_EXTENSION) ?: 'jpg';
            $uuid = (string) Str::uuid();
            $destino = "meal-evidence/{$evidencia->tenant_id}/{$uuid}.{$extension}";

            if (!$simulacro) {
                Storage::disk('local')->put($destino, file_get_contents($rutaPublica));
                @unlink($rutaPublica);
                $evidencia->update([
                    'path' => $destino,
                    'url' => "/api/v1/clock/meal-evidence/{$uuid}",
                ]);
            }
            $movidos++;
        }

        // 2. Archivos huérfanos que quedaron en la carpeta pública.
        $raizPublica = public_path('uploads/meal-evidence');
        if (is_dir($raizPublica)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raizPublica, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if (!$archivo->isFile()) {
                    continue;
                }
                $extension = $archivo->getExtension() ?: 'jpg';
                $destino = 'meal-evidence/huerfanos/' . Str::uuid() . ".{$extension}";

                if (!$simulacro) {
                    Storage::disk('local')->put($destino, file_get_contents($archivo->getPathname()));
                    @unlink($archivo->getPathname());
                }
                $huerfanos++;
            }
        }

        $prefijo = $simulacro ? '[SIMULACRO] ' : '';
        $this->info("{$prefijo}Movidas al disco privado: {$movidos} con registro, {$huerfanos} huérfanas. Sin archivo en disco: {$noEncontrados}.");

        if ($huerfanos > 0) {
            $this->warn($simulacro
                ? "Hay {$huerfanos} huérfanas en la carpeta PÚBLICA. Corre el comando sin --dry-run para sacarlas de ahí."
                : "Las {$huerfanos} huérfanas quedaron en storage/app/private/meal-evidence/huerfanos/ — ya no son públicas. Borrarlas es decisión del dueño de la empresa.");
        }

        return self::SUCCESS;
    }

    /** La ruta física vieja: `path` absoluto de la fila, o reconstruida desde `url`. */
    private function rutaPublicaDe(MealPhotoEvidence $evidencia): ?string
    {
        if ($evidencia->path && file_exists($evidencia->path)) {
            return $evidencia->path;
        }

        if ($evidencia->url && str_starts_with($evidencia->url, '/uploads/')) {
            return public_path(ltrim($evidencia->url, '/'));
        }

        return null;
    }
}
