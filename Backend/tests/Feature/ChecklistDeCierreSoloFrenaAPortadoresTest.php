<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El checklist de cierre es de quien CIERRA (2026-08-22, prueba en vivo).
 *
 * Con "checklist de cierre obligatorio" activo, la salida de TODA la plantilla quedaba frenada
 * hasta que el encargado lo completara: un colaborador sin llaves que salía antes por enfermedad,
 * con el PIN de su supervisor ya validado, recibía "Completa el checklist de cierre" — un
 * checklist que no es suyo y que no puede completar. Ahora sólo frena a los portadores de
 * llaves (asignación activa con permiso de abrir), que son quienes cierran.
 */
class ChecklistDeCierreSoloFrenaAPortadoresTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Cierre QA', 'subdomain' => 'cierreqa', 'plan' => 'enterprise', 'is_active' => true]);
        DB::table('store_opening_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'store_id' => 1],
            ['company_id' => 1, 'require_closing_checklist' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function persona(string $nombre, bool $conLlaves): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@cierreqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
        if ($conLlaves) {
            DB::table('store_opening_assignments')->insert([
                'tenant_id' => $this->tenant->id, 'company_id' => 1, 'store_id' => 1,
                'employee_id' => $emp->id, 'priority_order' => 1, 'can_open_store' => true,
                'has_keys' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    public function test_sin_llaves_se_puede_salir_aunque_falte_el_checklist_de_cierre(): void
    {
        $miguel = $this->persona('Miguel Sin Llaves', false);
        $reloj = app(ClockService::class);
        $reloj->processPunch($miguel, 'check_in');

        $r = $reloj->processPunch($miguel, 'check_out');

        $this->assertTrue($r['success'], 'un colaborador sin llaves no tiene por qué completar el checklist de cierre');
        $this->assertDatabaseHas('time_entries', ['user_id' => $miguel->id, 'type' => 'check_out']);
    }

    public function test_con_llaves_el_checklist_de_cierre_sigue_siendo_obligatorio(): void
    {
        $adan = $this->persona('Adan Con Llaves', true);
        $reloj = app(ClockService::class);
        $reloj->processPunch($adan, 'check_in');

        try {
            $reloj->processPunch($adan, 'check_out');
            $this->fail('el portador de llaves salió sin completar el checklist de cierre');
        } catch (\Exception $e) {
            $this->assertStringContainsString('checklist de cierre', $e->getMessage());
        }
        $this->assertDatabaseMissing('time_entries', ['user_id' => $adan->id, 'type' => 'check_out']);
    }
}
