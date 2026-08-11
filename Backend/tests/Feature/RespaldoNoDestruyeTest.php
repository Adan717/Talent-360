<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El respaldo funciona y NO destruye nada (2026-08-11).
 *
 * El módulo no tenía ni una sola prueba, y estaba muerto: la lista de tablas empezaba por
 * `companies`, que NO tiene columna `tenant_id`, así que exportar, reponer y "subir a Drive"
 * reventaban con 500 en Postgres. Ese 500 era lo único que impedía el desastre de abajo.
 *
 * Al restaurar se borraban con DELETE las filas de la empresa y luego se reinsertaba lo del
 * archivo. Como `employees` NO viajaba en el respaldo y sus llaves foráneas hacia `users` y
 * `job_roles` son ON DELETE SET NULL, cada expediente quedaba con `user_id` y `job_role_id` en
 * NULL para siempre: la plantilla entera sin cuenta de acceso y sin puesto. Y ese mismo borrado
 * arrastraba por CASCADE una docena de tablas que tampoco están en el respaldo.
 *
 * Estas pruebas corren también en Postgres (`phpunit.postgres.xml`), que es donde el fallo de
 * `companies` era visible: en sqlite la comilla doble se degrada a literal y el bug es invisible.
 */
class RespaldoNoDestruyeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Respaldo QA', 'subdomain' => 'respaldoqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@respaldoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function colaborador(string $correo = 'colab@respaldoqa.test'): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador Uno', 'email' => $correo,
            'password' => Hash::make('suclave123'), 'role' => 'empleado', 'is_active' => true,
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Colaborador Uno', 'email' => $correo,
            'job_role_id' => $this->puesto->id, 'is_active_employee' => true,
            'salary' => 3000, 'base_salary' => 3000, 'curp' => 'CURP123456HDFABC01',
        ]);
    }

    private function exportar(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/tenant/backup/export')
            ->assertStatus(200)
            ->json();
    }

    private function reponer(array $respaldo): \Illuminate\Testing\TestResponse
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/tenant/backup/import', [
            'backup_json' => json_encode($respaldo),
        ]);

        if ($r->status() === 500) {
            $this->fail('reponer falló: ' . ($r->json('message') ?? 'sin mensaje'));
        }

        return $r;
    }

    // --- Que exista siquiera -------------------------------------------------------------

    public function test_exportar_funciona_y_trae_a_la_plantilla(): void
    {
        $ficha = $this->colaborador();

        $respaldo = $this->exportar();

        $this->assertArrayHasKey('employees', $respaldo['data'],
            'un "respaldo completo" sin los expedientes no respalda lo que importa');
        $correos = collect($respaldo['data']['employees'])->pluck('email');
        $this->assertContains($ficha->email, $correos);
        $this->assertNotEmpty($respaldo['_signature']);
    }

    public function test_el_respaldo_no_saca_contrasenas_ni_pines_del_servidor(): void
    {
        $this->colaborador();

        $respaldo = $this->exportar();

        foreach ($respaldo['data']['users'] as $fila) {
            $fila = (array) $fila;
            foreach (['password', 'remember_token', 'two_factor_secret', 'biometric_key', 'qr_token'] as $columna) {
                $this->assertArrayNotHasKey($columna, $fila,
                    "el archivo se descarga a un ordenador cualquiera: {$columna} no puede viajar en él");
            }
        }

        foreach ($respaldo['data']['employees'] as $fila) {
            $this->assertArrayNotHasKey('kiosk_pin_hash', (array) $fila);
        }
    }

    // --- Que reponer NO destruya ----------------------------------------------------------

    public function test_reponer_no_desconecta_a_la_plantilla_de_su_cuenta_ni_de_su_puesto(): void
    {
        $ficha = $this->colaborador();
        $respaldo = $this->exportar();

        $this->reponer($respaldo)->assertStatus(200);

        $ficha->refresh();
        $this->assertSame($ficha->user_id, DB::table('employees')->where('id', $ficha->id)->value('user_id'),
            'el expediente tiene que seguir apuntando a su cuenta');
        $this->assertNotNull($ficha->user_id, 'sin cuenta, esa persona no puede entrar ni fichar');
        $this->assertNotNull($ficha->job_role_id, 'sin puesto, se cae su política de reloj y su tolerancia');
        $this->assertSame('CURP123456HDFABC01', $ficha->curp);
    }

    public function test_reponer_no_borra_lo_que_no_esta_en_el_respaldo(): void
    {
        $this->colaborador();
        $respaldo = $this->exportar();

        // Algo que ocurrió DESPUÉS del respaldo y que el archivo no contiene. `audit_logs` es una
        // de las tablas que el borrado se llevaba por cascada al eliminar `users`.
        DB::table('audit_logs')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id,
            'date' => '2026-08-10', 'type' => 'retardo', 'timestamp_str' => '09:12',
            'reason' => 'llegó tarde', 'details' => 'posterior al respaldo',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reponer($respaldo)->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', ['details' => 'posterior al respaldo']);
    }

    public function test_reponer_no_deja_a_nadie_sin_contrasena(): void
    {
        $ficha = $this->colaborador();
        $respaldo = $this->exportar();

        $this->reponer($respaldo)->assertStatus(200);

        // El respaldo no lleva contraseñas: reponer no puede pisar la que ya tiene la persona.
        $this->postJson('/api/v1/login', ['email' => $ficha->email, 'password' => 'suclave123'])
            ->assertStatus(200);
    }

    public function test_reponer_devuelve_de_verdad_lo_que_se_habia_perdido(): void
    {
        $ficha = $this->colaborador();
        $respaldo = $this->exportar();

        // Alguien borra el expediente después del respaldo.
        DB::table('employees')->where('id', $ficha->id)->delete();
        $this->assertDatabaseMissing('employees', ['id' => $ficha->id]);

        $this->reponer($respaldo)->assertStatus(200);

        $this->assertDatabaseHas('employees', ['id' => $ficha->id, 'email' => $ficha->email, 'curp' => 'CURP123456HDFABC01']);
    }

    public function test_un_respaldo_alterado_se_rechaza(): void
    {
        $this->colaborador();
        $respaldo = $this->exportar();
        $respaldo['data']['users'][0]['role'] = 'platform_admin';

        $r = $this->actingAs($this->admin)->postJson('/api/v1/tenant/backup/import', [
            'backup_json' => json_encode($respaldo),
        ]);

        $r->assertStatus(400);
        $this->assertSame('admin', DB::table('users')->where('id', $this->admin->id)->value('role'));
    }
}
