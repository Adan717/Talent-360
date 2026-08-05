<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3 (prueba en vivo 2026-07-29): al dar de alta a "Adán Cuéllar", el correo autogenerado salió
 * como `adáncuéllar@pruebaqa360.com` — con acentos.
 *
 * Un correo con diacríticos en la parte local necesita SMTPUTF8 (RFC 6531) para viajar; la
 * mayoría de los servidores y clientes lo rechazan, así que la invitación de bienvenida y
 * cualquier notificación posterior fallan en silencio. Además es un dolor de teclear en el
 * login desde el celular de un colaborador.
 *
 * Regla: el backend normaliza los diacríticos de la parte local al persistir, venga el correo
 * autogenerado por el frontend o escrito a mano. El dominio se respeta tal cual.
 */
class EmployeeEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    public function test_el_alta_quita_los_acentos_del_correo(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Adán Cuéllar',
            'email' => 'adáncuéllar@pruebaqa360.com',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('employees', ['email' => 'adancuellar@pruebaqa360.com']);
        $this->assertDatabaseHas('users', ['email' => 'adancuellar@pruebaqa360.com']);
    }

    public function test_normaliza_la_enie_y_las_mayusculas_acentuadas(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Íñigo Muñoz',
            'email' => 'ÍÑIGOmuñoz@empresa.com',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'inigomunoz@empresa.com']);
    }

    public function test_no_toca_un_correo_que_ya_es_valido(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Ana Lopez',
            'email' => 'ana.lopez+turno1@empresa.com.mx',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'ana.lopez+turno1@empresa.com.mx']);
    }

    public function test_al_editar_tambien_normaliza(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Rosa Perez', 'email' => 'rosa@empresa.com', 'role' => 'empleado',
            'hire_date' => '2026-08-01',
        ])->assertStatus(201);

        $id = DB::table('employees')->where('email', 'rosa@empresa.com')->value('id');

        $this->actingAs($admin)->putJson("/api/v1/employees/{$id}", [
            'email' => 'rosaGarcía@empresa.com',
        ])->assertStatus(200);

        $this->assertDatabaseHas('employees', ['id' => $id, 'email' => 'rosagarcia@empresa.com']);
    }
}
