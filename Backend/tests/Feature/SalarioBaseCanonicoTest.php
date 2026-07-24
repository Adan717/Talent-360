<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R78 (Fase 0, bug de dinero): la nómina lee `employees.base_salary ?? salary`, pero la UI de
 * RRHH escribía `salary`. En tenants con `base_salary` poblada, editar el sueldo no cambiaba el pago.
 * A partir de R78 la columna canónica es `base_salary` (la UI la escribe; la nómina la lee).
 */
class SalarioBaseCanonicoTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminEmpleado(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa S', 'subdomain' => 's' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $role = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Cajas']);
        $emp = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $admin->id, 'name' => 'Colab',
            'job_role_id' => $role->id, 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true, 'base_salary' => 3000.00,
        ]);
        return [$tenant, $admin, $emp];
    }

    private function base(Employee $emp): float
    {
        return (float) app(ClockService::class)
            ->calculatePayrollForEmployee($emp->fresh(), '2026-07-06', '2026-07-12')['salary']['base'];
    }

    /** Editar el sueldo por la API (base_salary) SÍ cambia la base de la nómina. */
    public function test_editar_base_salary_por_la_api_cambia_la_nomina(): void
    {
        [, $admin, $emp] = $this->makeAdminEmpleado();
        $this->assertSame(3000.0, $this->base($emp));

        $this->actingAs($admin)->putJson('/api/v1/employees/' . $emp->id, ['base_salary' => 5000])
            ->assertStatus(200);

        $this->assertSame(5000.0, $this->base($emp), 'la nomina debe reflejar el nuevo base_salary');
    }

    /**
     * EL BUG QUE SE CIERRA: un empleado con `base_salary` poblada; el pago se basa en `base_salary`,
     * no en `salary`. (Antes la UI escribia `salary` y este pago lo ignoraba.)
     */
    public function test_la_nomina_usa_base_salary_no_salary(): void
    {
        [, , $emp] = $this->makeAdminEmpleado();
        // base_salary=3000 (canonico), salary=9999 (lo que la UI vieja escribia): gana base_salary.
        DB::table('employees')->where('id', $emp->id)->update(['salary' => 9999.00]);

        $this->assertSame(3000.0, $this->base($emp), 'gana base_salary, no el salary viejo');
    }

    /** El backfill rellena `base_salary` desde `salary` SOLO donde esta vacia (sin tocar existentes). */
    public function test_backfill_solo_rellena_nulos(): void
    {
        [$tenant, , $empConBase] = $this->makeAdminEmpleado();
        // Empleado B: sin base_salary, solo salary (el caso legacy que hay que rescatar).
        $userB = User::create(['tenant_id' => $tenant->id, 'name' => 'B', 'email' => 'b' . uniqid() . '@t.local', 'password' => bcrypt('x'), 'role' => 'empleado']);
        $empB = Employee::create(['tenant_id' => $tenant->id, 'user_id' => $userB->id, 'name' => 'B', 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true, 'base_salary' => null, 'salary' => 4200.00]);
        // Empleado A ya tiene base_salary=3000 y le ponemos un salary distinto que NO debe ganar.
        DB::table('employees')->where('id', $empConBase->id)->update(['salary' => 1111.00]);

        // Replica el backfill de la migracion.
        DB::table('employees')->whereNull('base_salary')->whereNotNull('salary')
            ->update(['base_salary' => DB::raw('salary')]);

        $this->assertSame(4200.0, (float) $empB->fresh()->base_salary, 'B rescata su sueldo desde salary');
        $this->assertSame(3000.0, (float) $empConBase->fresh()->base_salary, 'A conserva su base_salary (no lo pisa el salary)');
    }
}
