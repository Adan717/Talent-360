<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `employees.hire_date` era opcional y NINGUNO de los dos puntos de alta la mandaba —ni el
 * asistente de onboarding ni Recursos Humanos—, así que estaba vacía en el 100% de los
 * colaboradores vivos (4 de 4 al medir la V2 el 2026-08-05).
 *
 * Es un dato de NEGOCIO: el día que la persona empezó a trabajar. De ahí cuelgan la antigüedad,
 * el aguinaldo y el finiquito cuando existan, y de ahí cuenta el aviso de "lleva N días sin
 * completar su inducción". A propósito NO se acepta `created_at` como sustituto silencioso: esa
 * es la fecha en que se creó el registro, y dar de alta un viernes a alguien que entra el lunes
 * son dos días distintos.
 */
class EmployeeHireDateObligatoriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        return $user->fresh();
    }

    private function alta(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/employees', array_merge([
            'name' => 'Colaborador Nuevo',
            'email' => 'nuevo@empresa.test',
            'password' => 'password123',
            'role' => 'empleado',
            'contract_type' => 'Tiempo Completo',
            'salary' => 9000,
            'is_active' => true,
        ], $extra));
    }

    public function test_el_alta_sin_fecha_de_ingreso_se_rechaza(): void
    {
        $this->alta()->assertStatus(422)->assertJsonValidationErrors('hire_date');

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@empresa.test']);
    }

    public function test_el_alta_con_fecha_de_ingreso_la_guarda(): void
    {
        // El caso que hace que `created_at` no sirva: se da de alta hoy, entra el lunes.
        $this->alta(['hire_date' => '2026-08-10'])->assertStatus(201);

        $this->assertDatabaseHas('employees', [
            'email' => 'nuevo@empresa.test',
            'hire_date' => '2026-08-10',
        ]);
    }

    public function test_la_migracion_rellena_los_expedientes_que_ya_existian(): void
    {
        // Un expediente viejo, de los que nacieron cuando el campo era opcional.
        $viejo = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'name' => 'Colaborador Antiguo',
            'email' => 'antiguo@empresa.test',
            'hire_date' => null,
            'created_at' => '2026-06-01 09:00:00',
            'updated_at' => now(),
        ]);

        // Es lo que hace la migración de relleno: la fecha del registro, que es lo más cercano
        // que hay. Aproximada a propósito y anotada como tal.
        DB::table('employees')->whereNull('hire_date')
            ->update(['hire_date' => DB::raw('DATE(created_at)')]);

        $this->assertSame('2026-06-01', substr((string) DB::table('employees')->where('id', $viejo)->value('hire_date'), 0, 10));
    }
}
