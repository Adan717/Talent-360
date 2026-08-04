<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reparación de rutinas de cierre para tenants pre-2026-08-03 (verificado en vivo: DecorArte
 * registraba el cierre pero repartía 0 tareas porque su giro se aplicó antes de que el wizard
 * creara la rutina).
 */
class RepararRutinasCierreTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 29;

    /** Simula un tenant configurado con el wizard VIEJO: tareas de cierre sí, rutina no. */
    private function sembrarTenantViejo(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Viejo QA', 'subdomain' => 'viejoqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rolId = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => 'Gerente', 'area' => 'Gerencia',
            'jerarquiaLlaves' => 1, 'esAperturador' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('routines')->insert([
            'tenant_id' => $this->tenantId, 'title' => 'Checklist Diario de Apertura',
            'trigger' => 'apertura', 'assign_mode' => 'fijo', 'target_role_id' => $rolId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Dos tareas con título EXACTO del catálogo de restaurante (momento=cierre) y una ajena.
        foreach ([
            'Inspección y cierre de llaves de gas principal',
            'Corte de caja, resguardo de efectivo y activación de alarma',
            'Tarea inventada que no es de ningún catálogo',
        ] as $titulo) {
            DB::table('tasks')->insert([
                'tenant_id' => $this->tenantId, 'title' => $titulo, 'estimated_mins' => 10,
                'priority' => 'normal', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_crea_la_rutina_con_las_tareas_de_cierre_reconocidas(): void
    {
        $this->sembrarTenantViejo();

        $this->artisan('reloj:reparar-rutinas-cierre', ['--tenant' => $this->tenantId])
            ->assertExitCode(0);

        $rutina = DB::table('routines')->where('tenant_id', $this->tenantId)
            ->where('trigger', 'cierre')->first();

        $this->assertNotNull($rutina, 'El tenant viejo debe quedar con su rutina de cierre.');
        $this->assertSame(2,
            DB::table('routine_task')->where('routine_id', $rutina->id)->count(),
            'Solo las tareas cuyo título viene de un catálogo; la inventada NO se agrupa.');

        // El mismo responsable que la apertura.
        $apertura = DB::table('routines')->where('tenant_id', $this->tenantId)
            ->where('trigger', 'apertura')->first();
        $this->assertSame($apertura->target_role_id, $rutina->target_role_id);
    }

    public function test_es_idempotente(): void
    {
        $this->sembrarTenantViejo();

        $this->artisan('reloj:reparar-rutinas-cierre', ['--tenant' => $this->tenantId])->assertExitCode(0);
        $this->artisan('reloj:reparar-rutinas-cierre', ['--tenant' => $this->tenantId])->assertExitCode(0);

        $this->assertSame(1, DB::table('routines')->where('tenant_id', $this->tenantId)
            ->where('trigger', 'cierre')->count(), 'Repetir la reparación no duplica rutinas.');
    }

    public function test_dry_run_no_toca_nada(): void
    {
        $this->sembrarTenantViejo();

        $this->artisan('reloj:reparar-rutinas-cierre', ['--tenant' => $this->tenantId, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('routines')->where('tenant_id', $this->tenantId)
            ->where('trigger', 'cierre')->count());
    }

    public function test_no_crea_rutinas_vacias(): void
    {
        // Tenant con apertura pero cuyas tareas no coinciden con ningún catálogo (giro custom).
        DB::table('tenants')->insertOrIgnore([
            'id' => 31, 'name' => 'Custom QA', 'subdomain' => 'customqa31', 'plan' => 'basic',
            'max_users' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('routines')->insert([
            'tenant_id' => 31, 'title' => 'Apertura', 'trigger' => 'apertura',
            'assign_mode' => 'fijo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('reloj:reparar-rutinas-cierre', ['--tenant' => 31])->assertExitCode(0);

        $this->assertSame(0, DB::table('routines')->where('tenant_id', 31)
            ->where('trigger', 'cierre')->count(),
            'Una rutina vacía en el panel es una promesa vacía (familia H19/H23).');
    }
}
