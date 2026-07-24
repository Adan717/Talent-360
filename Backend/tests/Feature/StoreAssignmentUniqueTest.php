<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * store_opening_assignments: un empleado NO debe tener dos asignaciones de apertura en el
 * mismo tenant. StoreOpeningController::createAssignment ya lo previene por código
 * (exists check), pero sin un unique una apertura concurrente (o un insert directo) podía
 * duplicar al opener en la jerarquía. Este constraint es el backstop de integridad.
 */
class StoreAssignmentUniqueTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub,
            'subdomain' => $sub,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    private function makeEmployee(int $tenantId, string $name): int
    {
        // store_opening_assignments.employee_id es FK a employees.id (migración
        // 2026_07_07_192928_fix_store_opening_assignments_foreign_key).
        return DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $tenantId . '@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAssignment(int $tenantId, int $employeeId, int $priority = 1): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $tenantId,
            'company_id' => 1,
            'store_id' => 1,
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_assignment_is_rejected(): void
    {
        $tenant = $this->makeTenant('dup-assign');
        $empId = $this->makeEmployee($tenant->id, 'Emp A');

        $this->insertAssignment($tenant->id, $empId, 1);

        // Segunda asignación del mismo empleado en el mismo tenant → viola el unique.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertAssignment($tenant->id, $empId, 2);
    }

    public function test_different_employees_and_tenants_allowed(): void
    {
        $a = $this->makeTenant('assign-a');
        $b = $this->makeTenant('assign-b');
        $empA1 = $this->makeEmployee($a->id, 'A Uno');
        $empA2 = $this->makeEmployee($a->id, 'A Dos');
        $empB1 = $this->makeEmployee($b->id, 'B Uno');

        // Distintos empleados del mismo tenant: OK. Mismo "slot" en otro tenant: OK.
        $this->insertAssignment($a->id, $empA1, 1);
        $this->insertAssignment($a->id, $empA2, 2);
        $this->insertAssignment($b->id, $empB1, 1);

        $this->assertSame(3, DB::table('store_opening_assignments')->count());
    }
}
