<?php

namespace App\Console\Commands;

use App\Support\CatalogoOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparación: cursos de Academia en tenants configurados ANTES del 2026-08-05 (AC1/AC2).
 *
 * Hasta el commit de AC1/AC2, el asistente inyectaba los cursos desde una lista escrita a mano
 * dentro de `configureNicho` y los colgaba TODOS del puesto de mando. El resultado medido en la
 * V2: los tenants creados por el asistente tenían UN solo curso genérico, apuntando a su
 * "Administrador Gerente" — de los tres colaboradores del tenant 2, dos veían cero cursos.
 *
 * Patrón de la casa: "al corregir algo que genera datos, preguntar siempre qué pasa con lo ya
 * generado". Esto es la respuesta para la Academia.
 *
 * Qué hace, por cada tenant que ya tenga su giro configurado (`system_settings.nicho_configurado`):
 *  - Toma los cursos del catálogo de ESE giro.
 *  - Los que ya existen (mismo título) se actualizan: descripción, tipo, examen y sobre todo el
 *    puesto al que van. Los que faltan se crean.
 *  - NO borra ningún curso: los que el administrador haya dado de alta por su cuenta se quedan
 *    como están, y el progreso de los colaboradores no se toca (borrar un curso se lo llevaría
 *    por delante en cascada).
 *  - Reasignar el puesto sólo AMPLÍA quién ve el curso (del gerente a toda la plantilla), nunca
 *    se lo quita a nadie.
 *
 *   php artisan academia:reparar-cursos-del-giro [--dry-run] [--tenant=]
 *
 * Es un comando y no una migración a propósito: toca datos de empresas vivas, así que quien
 * opera decide cuándo y sobre qué tenant, después de verlo en seco.
 */
class RepararCursosDelGiroCommand extends Command
{
    protected $signature = 'academia:reparar-cursos-del-giro {--dry-run : Solo mostrar qué haría} {--tenant= : Limitar a un tenant}';

    protected $description = 'Repone los cursos del giro y su reparto por puesto en tenants configurados antes de AC1/AC2';

    public function handle(): int
    {
        $enSeco = (bool) $this->option('dry-run');

        $configuraciones = DB::table('system_settings')
            ->where('key', 'nicho_configurado')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->get();

        if ($configuraciones->isEmpty()) {
            $this->info('Ningún tenant tiene giro configurado: nada que reparar.');

            return self::SUCCESS;
        }

        $reparados = 0;

        foreach ($configuraciones as $config) {
            $tenantId = (int) $config->tenant_id;
            $nicho = json_decode($config->value, true)['nicho'] ?? null;

            if (!$nicho) {
                $this->warn("Tenant {$tenantId}: su giro no se puede leer, se salta.");
                continue;
            }

            $catalogo = CatalogoOnboarding::para($nicho);

            if ($catalogo === null || empty($catalogo['cursos'])) {
                $this->warn("Tenant {$tenantId}: el giro '{$nicho}' no tiene cursos en el catálogo, se salta.");
                continue;
            }

            $puestos = DB::table('job_roles')->where('tenant_id', $tenantId)
                ->pluck('id', 'name')->all();

            $creados = 0;
            $reasignados = 0;

            foreach ($catalogo['cursos'] as $curso) {
                $puestoDestino = !empty($curso['target_role_name'])
                    ? ($puestos[$curso['target_role_name']] ?? null)
                    : null;

                $existente = DB::table('academy_courses')
                    ->where('tenant_id', $tenantId)
                    ->where('title', $curso['title'])
                    ->first();

                $datos = [
                    'description' => $curso['description'] ?? '',
                    'course_type' => $curso['course_type'] ?? 'training',
                    'target_job_role_id' => $puestoDestino,
                    'quiz_data' => json_encode($curso['quiz'] ?? []),
                    'updated_at' => now(),
                ];

                if ($existente) {
                    if ((int) $existente->target_job_role_id === (int) $puestoDestino) {
                        continue;
                    }

                    $reasignados++;

                    if (!$enSeco) {
                        DB::table('academy_courses')->where('id', $existente->id)->update($datos);
                    }

                    continue;
                }

                $creados++;

                if (!$enSeco) {
                    DB::table('academy_courses')->insert($datos + [
                        'tenant_id' => $tenantId,
                        'title' => $curso['title'],
                        'video_url' => $curso['video_url'] ?? '',
                        'is_active' => true,
                        'created_at' => now(),
                    ]);
                }
            }

            if ($creados === 0 && $reasignados === 0) {
                continue;
            }

            $reparados++;
            $this->line("Tenant {$tenantId} ({$nicho}): {$creados} curso(s) que faltaban, "
                . "{$reasignados} reasignado(s) al puesto que les toca.");
        }

        $this->info($enSeco
            ? "En seco: {$reparados} tenant(s) por reparar. Repite sin --dry-run para aplicarlo."
            : "Listo: {$reparados} tenant(s) reparados.");

        return self::SUCCESS;
    }
}
