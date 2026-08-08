<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reportes básicos (ronda 2026-08-08).
 *
 * Los dos botones "Descargar CSV" de la pestaña gratuita NO tenían handler: no bajaban
 * nada ni avisaban. Estos son los reportes de verdad, con lo que un reporte real exige:
 * su empresa y nada más, sin datos del simulador, y legible en Excel en español.
 */
class ReportesBasicosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $mio;
    private Tenant $ajeno;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mio = Tenant::create([
            'name' => 'Mi Empresa', 'subdomain' => 'miempresa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $this->ajeno = Tenant::create([
            'name' => 'Otra Empresa', 'subdomain' => 'otraempresa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Jefa', 'email' => 'jefa@miempresa.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function fichaje(int $tenantId, ?int $userId, string $date, array $extra = []): void
    {
        DB::table('time_entries')->insert(array_merge([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'date' => $date,
            'type' => 'check_in', 'time' => '09:15:00',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function csv(string $url): string
    {
        $res = $this->actingAs($this->admin)->get($url);
        $res->assertOk();

        return $res->streamedContent();
    }

    public function test_asistencia_trae_solo_mi_empresa(): void
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();

        $mio = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Colaborador Mío',
            'email' => 'mio@miempresa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $ajeno = User::create([
            'tenant_id' => $this->ajeno->id, 'name' => 'Colaborador Ajeno',
            'email' => 'ajeno@otraempresa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->fichaje($this->mio->id, $mio->id, $hoy, ['employee_name_at_time' => 'Colaborador Mío']);
        $this->fichaje($this->ajeno->id, $ajeno->id, $hoy, ['employee_name_at_time' => 'Colaborador Ajeno']);

        $csv = $this->csv('/api/v1/admin/reports/asistencia.csv');

        $this->assertStringContainsString('Colaborador Mío', $csv);
        $this->assertStringNotContainsString('Colaborador Ajeno', $csv);
    }

    public function test_asistencia_no_mezcla_fichajes_del_simulador(): void
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();

        $u = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Real', 'email' => 'real@miempresa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $sesionSim = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => $this->mio->id, 'started_by_user_id' => $this->admin->id,
            'simulated_date' => $hoy, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fichaje($this->mio->id, $u->id, $hoy, ['employee_name_at_time' => 'Fichaje Real']);
        $this->fichaje($this->mio->id, $u->id, $hoy, [
            'employee_name_at_time' => 'Fichaje Simulado',
            'simulation_session_id' => $sesionSim,
        ]);

        $csv = $this->csv('/api/v1/admin/reports/asistencia.csv');

        $this->assertStringContainsString('Fichaje Real', $csv);
        $this->assertStringNotContainsString('Fichaje Simulado', $csv,
            'los datos del Simulador Matrix nunca se mezclan con un reporte real');
    }

    public function test_el_csv_abre_con_acentos_correctos_en_excel(): void
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();
        $u = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Ramón Núñez', 'email' => 'ramon@miempresa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $this->fichaje($this->mio->id, $u->id, $hoy, ['employee_name_at_time' => 'Ramón Núñez']);

        $csv = $this->csv('/api/v1/admin/reports/asistencia.csv');

        // Sin el BOM, Excel en español abre "RamÃ³n" — y este es el primer reporte que ve el cliente.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Ramón Núñez', $csv);
    }

    public function test_tareas_completadas_solo_las_cerradas_de_mi_empresa(): void
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();

        $u = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Trabajador', 'email' => 'trab@miempresa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $mia = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->mio->id, 'title' => 'Corte de caja mío',
            'estimated_mins' => 20, 'points' => 10, 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ajena = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->ajeno->id, 'title' => 'Tarea ajena',
            'estimated_mins' => 20, 'points' => 10, 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$mia, 'completed'], [$ajena, 'completed']] as [$taskId, $status]) {
            DB::table('task_assignments')->insert([
                'id' => (string) Str::uuid(), 'task_id' => $taskId, 'user_id' => $u->id,
                'status' => $status, 'date' => $hoy, 'points_awarded' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Una pendiente del propio tenant: no es "completada".
        $pendiente = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->mio->id, 'title' => 'Tarea pendiente mía',
            'estimated_mins' => 10, 'points' => 5, 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('task_assignments')->insert([
            'id' => (string) Str::uuid(), 'task_id' => $pendiente, 'user_id' => $u->id,
            'status' => 'pending', 'date' => $hoy,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->csv('/api/v1/admin/reports/tareas.csv');

        $this->assertStringContainsString('Corte de caja mío', $csv);
        $this->assertStringNotContainsString('Tarea ajena', $csv);
        $this->assertStringNotContainsString('Tarea pendiente mía', $csv);
    }

    public function test_un_colaborador_raso_no_baja_los_reportes(): void
    {
        $raso = User::create([
            'tenant_id' => $this->mio->id, 'name' => 'Raso', 'email' => 'raso@miempresa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->actingAs($raso)->getJson('/api/v1/admin/reports/asistencia.csv')->assertForbidden();
        $this->actingAs($raso)->getJson('/api/v1/admin/reports/tareas.csv')->assertForbidden();
    }
}
