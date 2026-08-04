<?php

namespace App\Console\Commands;

use App\Support\CatalogoOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparación: rutinas de cierre para tenants configurados ANTES del 2026-08-03.
 *
 * El wizard crea la rutina trigger='cierre' desde el commit 6e0f46e (cuando nació su
 * consumidor, el botón "Cerrar sucursal"). Las empresas que aplicaron su giro antes tienen las
 * TAREAS de cierre (el catálogo siempre las creó) pero ninguna rutina que las agrupe: el botón
 * registra el cierre y reparte 0 tareas — verificado en vivo en DecorArte (tenant 2 de la V2).
 *
 * Patrón de la casa: "al corregir algo que genera datos, preguntar siempre qué pasa con lo ya
 * generado". Esto es la respuesta para el cierre.
 *
 * Qué hace, por cada tenant con rutina de 'apertura' pero sin 'cierre':
 *  - Busca las tareas EXISTENTES del tenant cuyo título coincida con las tareas de
 *    momento='cierre' de cualquier catálogo JSON (matching por título: las tareas del tenant
 *    nacieron de esos mismos catálogos, el título es la llave natural).
 *  - Crea la rutina "Checklist Diario de Cierre" con los MISMOS campos que el wizard
 *    (assign_mode 'fijo', target_role_id = el mismo puesto a cargo de la apertura) y vincula
 *    esas tareas. NO borra ni recrea ninguna tarea: los tenants están vivos.
 *  - Idempotente: si el tenant ya tiene rutina de cierre, se salta.
 *
 *   php artisan reloj:reparar-rutinas-cierre [--dry-run] [--tenant=]
 */
class RepararRutinasCierreCommand extends Command
{
    protected $signature = 'reloj:reparar-rutinas-cierre {--dry-run : Solo mostrar qué haría} {--tenant= : Limitar a un tenant}';

    protected $description = 'Crea la rutina de cierre en tenants configurados antes de que el wizard la creara';

    public function handle(): int
    {
        // Todos los títulos de cierre de todos los catálogos instalados.
        $titulosCierre = collect(CatalogoOnboarding::giros())
            ->flatMap(fn ($g) => collect(CatalogoOnboarding::para($g)['tareas'])
                ->filter(fn ($t) => ($t['momento'] ?? null) === 'cierre')
                ->pluck('title'))
            ->unique()
            ->values();

        if ($titulosCierre->isEmpty()) {
            $this->error('Ningún catálogo declara tareas de cierre: nada que reparar.');

            return self::FAILURE;
        }

        $aperturas = DB::table('routines')->where('trigger', 'apertura')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->get()
            ->groupBy('tenant_id');

        $reparados = 0;

        foreach ($aperturas as $tenantId => $rutinasApertura) {
            $yaTiene = DB::table('routines')->where('tenant_id', $tenantId)
                ->where('trigger', 'cierre')->exists();

            if ($yaTiene) {
                $this->line("· Tenant {$tenantId}: ya tiene rutina de cierre — se salta.");
                continue;
            }

            $tareas = DB::table('tasks')->where('tenant_id', $tenantId)
                ->whereIn('title', $titulosCierre)->pluck('id');

            if ($tareas->isEmpty()) {
                $this->warn("· Tenant {$tenantId}: sin tareas de cierre reconocibles — no se crea rutina vacía.");
                continue;
            }

            // El mismo responsable que la apertura: es la decisión que tomó el wizard para
            // este tenant y la que el encargado de llaves ya conoce.
            $targetRole = $rutinasApertura->first()->target_role_id;

            if ($this->option('dry-run')) {
                $this->info("· Tenant {$tenantId}: crearía rutina de cierre con {$tareas->count()} tareas (rol {$targetRole}).");
                continue;
            }

            DB::transaction(function () use ($tenantId, $targetRole, $tareas) {
                $rutinaId = DB::table('routines')->insertGetId([
                    'tenant_id' => $tenantId,
                    'title' => 'Checklist Diario de Cierre',
                    'trigger' => 'cierre',
                    'assign_mode' => 'fijo',
                    'target_role_id' => $targetRole,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($tareas as $taskId) {
                    DB::table('routine_task')->insert([
                        'routine_id' => $rutinaId,
                        'task_id' => $taskId,
                    ]);
                }
            });

            $this->info("✔ Tenant {$tenantId}: rutina de cierre creada con {$tareas->count()} tareas.");
            $reparados++;
        }

        $this->line('');
        $this->info("Reparados: {$reparados} tenant(s).");

        return self::SUCCESS;
    }
}
