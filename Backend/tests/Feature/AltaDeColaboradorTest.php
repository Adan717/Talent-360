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
 * El alta deja expedientes utilizables (decisiones del dueño, 2026-08-08).
 *
 *  - SUELDO OBLIGATORIO: sin él, la nómina sustituía el hueco por un default escondido de
 *    $2,400 y la persona aparecía con un sueldo que nadie tecleó.
 *  - TURNO: si el alta no lo declara, se hereda el de la EMPRESA. Antes quedaba NULL y el
 *    reloj asumía 09:00 para todos: a quien entra a las 11:00 le contaba dos horas de retardo
 *    desde su primer día.
 *  - CONTRASEÑA: toda alta nacía con la cadena fija `password123` — la misma para toda la
 *    plantilla de todas las empresas, publicada en el propio código.
 */
class AltaDeColaboradorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Alta QA', 'subdomain' => 'altaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@altaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function darDeAlta(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees', array_merge([
            'name' => 'Nuevo Colaborador',
            'email' => 'nuevo@altaqa.test',
            'role' => 'empleado',
            'job_role_id' => $this->puesto->id,
            'hire_date' => now()->toDateString(),
            'salary' => 3000,
        ], $cambios));
    }

    // --- Sueldo obligatorio ------------------------------------------------------------

    public function test_no_se_puede_dar_de_alta_sin_sueldo(): void
    {
        $this->darDeAlta(['salary' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('salary');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_tampoco_con_sueldo_cero(): void
    {
        $this->darDeAlta(['salary' => 0])->assertStatus(422);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_con_sueldo_se_da_de_alta_y_queda_en_las_dos_columnas(): void
    {
        $this->darDeAlta(['salary' => 3000])->assertStatus(201);

        $ficha = DB::table('employees')->first();
        $this->assertEquals(3000, $ficha->salary);
        $this->assertEquals(3000, $ficha->base_salary, 'base_salary es la columna que lee la nómina');
    }

    // --- Turno heredado de la empresa --------------------------------------------------

    public function test_sin_horario_hereda_el_de_la_empresa(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'storeSchedule'],
            ['value' => json_encode(['openTime' => '11:18', 'closeTime' => '19:23']), 'created_at' => now(), 'updated_at' => now()]
        );

        $this->darDeAlta()->assertStatus(201);

        $ficha = DB::table('employees')->first();
        // Se compara H:i: sqlite guarda '11:18' tal cual y Postgres normaliza a '11:18:00'.
        // Comparar la cadena completa hace que la prueba pase en sqlite y falle en producción.
        $this->assertSame('11:18', substr($ficha->shiftStart, 0, 5), 'el turno sale del horario de la empresa, no de un 09:00 inventado');
        $this->assertSame('19:23', substr($ficha->shiftEnd, 0, 5));
    }

    public function test_si_el_alta_declara_horario_ese_manda(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'storeSchedule'],
            ['value' => json_encode(['openTime' => '11:18', 'closeTime' => '19:23']), 'created_at' => now(), 'updated_at' => now()]
        );

        $this->darDeAlta(['shiftStart' => '07:00', 'shiftEnd' => '15:00'])->assertStatus(201);

        $ficha = DB::table('employees')->first();
        $this->assertSame('07:00', substr($ficha->shiftStart, 0, 5));
        $this->assertSame('15:00', substr($ficha->shiftEnd, 0, 5));
    }

    // --- Contraseña --------------------------------------------------------------------

    public function test_ninguna_alta_nace_con_la_contrasena_de_siempre(): void
    {
        $this->darDeAlta()->assertStatus(201);

        $hash = DB::table('users')->where('email', 'nuevo@altaqa.test')->value('password');

        $this->assertFalse(Hash::check('password123', $hash),
            'toda la plantilla compartía la misma contraseña, y estaba escrita en el código');
    }

    public function test_la_persona_fija_su_contrasena_al_activarse(): void
    {
        $this->darDeAlta()->assertStatus(201);

        $ficha = Employee::first();
        $pin = DB::table('employees')->where('id', $ficha->id)->value('pin_code') ?: '123456';
        DB::table('employees')->where('id', $ficha->id)->update(['pin_code' => $pin]);

        $this->postJson('/api/v1/public/onboarding/complete', [
            'user_id' => $ficha->user_id,
            'pin' => $pin,
            'name' => 'Nuevo Colaborador',
            'password' => 'mi-clave-secreta',
            'password_confirmation' => 'mi-clave-secreta',
        ])->assertOk();

        // Y con ella entra de verdad.
        $this->postJson('/api/v1/login', [
            'email' => 'nuevo@altaqa.test',
            'password' => 'mi-clave-secreta',
        ])->assertOk();
    }

    // --- Aislamiento y unicidad --------------------------------------------------------

    public function test_no_se_puede_asignar_un_puesto_de_otra_empresa(): void
    {
        $otra = Tenant::create([
            'name' => 'Otra', 'subdomain' => 'otraalta', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $puestoAjeno = JobRole::create([
            'tenant_id' => $otra->id, 'name' => 'Ajeno', 'area' => 'X',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->darDeAlta(['job_role_id' => $puestoAjeno->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_role_id');
    }

    public function test_cambiar_el_correo_a_uno_ya_usado_avisa_en_vez_de_reventar(): void
    {
        $this->darDeAlta()->assertStatus(201);
        $ficha = Employee::first();

        // Otra persona ya tiene ese correo.
        User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Otro', 'email' => 'ocupado@altaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$ficha->id}", ['email' => 'ocupado@altaqa.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_guardar_la_ficha_con_su_propio_correo_sigue_funcionando(): void
    {
        $this->darDeAlta()->assertStatus(201);
        $ficha = Employee::first();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$ficha->id}", [
                'email' => 'nuevo@altaqa.test',
                'phone' => '5512345678',
            ])
            ->assertOk();
    }
}
