<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Contratar desde el ATS: lo que pasa aguas abajo (2026-08-11).
 *
 * El ATS crea usuario y expediente por su cuenta, saltándose el alta de RRHH. Este archivo cubre
 * los defectos que tenía ese atajo:
 *
 *  - SECUESTRO: la cuenta se buscaba sólo por correo, sin empresa. Como `users.email` es único
 *    global, contratar a alguien cuyo correo ya existía en OTRA empresa se apropiaba de esa
 *    cuenta y la degradaba a 'empleado' — el admin del vecino perdía su panel.
 *  - REINGRESO FANTASMA: `withoutGlobalScopes()` a secas también apaga el filtro de borrado
 *    lógico, así que encontraba al ex-colaborador ARCHIVADO y lo marcaba activo SIN `restore()`.
 *    Quedaba "contratado" pero invisible: no podía entrar, su PIN no servía y no salía ni en
 *    RRHH ni en nómina.
 *  - SUELDO Y HORARIO: el expediente nacía sin sueldo (la nómina rellena con $2,400 que nadie
 *    tecleó) y con 09:00–18:00 en duro, ignorando el horario de la empresa.
 */
class AtsContratacionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;
    private Vacancy $vacante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'ATS QA', 'subdomain' => 'atsqa', 'public_slug' => 'atsqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@atsqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);

        $this->vacante = Vacancy::create([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $this->puesto->id,
            'title' => 'Cajero', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);
    }

    private function candidato(array $cambios = []): Candidate
    {
        return Candidate::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'applied_vacancy_id' => $this->vacante->id,
            'name' => 'Ana Candidata',
            'email' => 'ana@candidata.test',
            'status' => 'evaluation',
        ], $cambios));
    }

    private function contratar(Candidate $c): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/candidates/{$c->id}", ['status' => 'hired']);
    }

    // --- Secuestro de una cuenta de otra empresa ---------------------------------------

    public function test_contratar_no_toca_la_cuenta_de_otra_empresa(): void
    {
        $otra = Tenant::create([
            'name' => 'La Vecina', 'subdomain' => 'vecina', 'public_slug' => 'vecina',
            'plan' => 'pro', 'is_active' => true,
        ]);
        $adminAjeno = User::create([
            'tenant_id' => $otra->id, 'name' => 'Dueño Vecino', 'email' => 'duenio@vecina.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);

        $c = $this->candidato(['email' => 'duenio@vecina.test']);

        $this->contratar($c)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $adminAjeno->refresh();
        $this->assertSame('admin', $adminAjeno->role, 'contratar en MI empresa no puede degradar al admin de OTRA');
        $this->assertSame($otra->id, $adminAjeno->tenant_id);

        $this->assertDatabaseMissing('employees', ['email' => 'duenio@vecina.test']);
        $this->assertSame('evaluation', DB::table('candidates')->where('id', $c->id)->value('status'),
            'si no se pudo contratar, el candidato no puede quedar marcado como contratado');
    }

    public function test_contratar_a_quien_ya_es_admin_de_la_misma_empresa_no_lo_degrada(): void
    {
        $c = $this->candidato(['email' => 'jefa@atsqa.test', 'name' => 'Jefa']);

        $this->contratar($c)->assertStatus(200);

        $this->admin->refresh();
        $this->assertSame('admin', $this->admin->role, 'el rol es un eje aparte del expediente: contratar no lo cambia');
        $this->assertTrue((bool) $this->admin->is_active);
    }

    // --- Reingreso de un ex-colaborador archivado --------------------------------------

    public function test_recontratar_a_un_archivado_lo_reingresa_de_verdad(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Vuelve Ramírez',
            'email' => 'vuelve@atsqa.test', 'password' => Hash::make('miclave123'),
            'role' => 'empleado', 'is_active' => true,
        ]);
        $ficha = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Vuelve Ramírez', 'email' => 'vuelve@atsqa.test',
            'job_role_id' => $this->puesto->id, 'is_active_employee' => true,
            'salary' => 3000, 'base_salary' => 3000,
        ]);

        // Así es como los archiva RRHH cuando hay historia laboral que conservar.
        $ficha->update(['is_active_employee' => false]);
        $ficha->delete();
        $user->update(['is_active' => false]);
        $user->delete();

        $c = $this->candidato(['email' => 'vuelve@atsqa.test', 'name' => 'Vuelve Ramírez']);
        $this->contratar($c)->assertStatus(200);

        $this->assertNull(Employee::withoutGlobalScopes()->withTrashed()->find($ficha->id)->deleted_at,
            'el expediente tiene que volver a la vida, no quedarse borrado con is_active_employee=true');
        $this->assertNull(User::withoutGlobalScopes()->withTrashed()->find($user->id)->deleted_at,
            'sin restore() la persona no puede iniciar sesión ni activar su cuenta');

        // Y se comprueba de verdad, no sólo la columna: el login vuelve a funcionar.
        $this->postJson('/api/v1/login', ['email' => 'vuelve@atsqa.test', 'password' => 'miclave123'])
            ->assertStatus(200);
    }

    // --- Sueldo y horario ---------------------------------------------------------------

    public function test_el_contratado_hereda_el_horario_de_la_empresa(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'storeSchedule'],
            ['value' => json_encode(['openTime' => '11:18', 'closeTime' => '19:23']), 'created_at' => now(), 'updated_at' => now()]
        );

        $c = $this->candidato();
        $this->contratar($c)->assertStatus(200);

        $ficha = DB::table('employees')->where('email', 'ana@candidata.test')->first();
        // sqlite guarda '11:18' y Postgres normaliza a '11:18:00'.
        $this->assertSame('11:18', substr($ficha->shiftStart, 0, 5),
            'el turno sale del horario de la empresa, no de un 09:00 en duro');
        $this->assertSame('19:23', substr($ficha->shiftEnd, 0, 5));
    }

    /**
     * La ficha del candidato queda apuntando a la cuenta que salió de ella.
     *
     * (2026-08-22, fase 9) `candidates.user_id` existe desde el principio y nadie la llenaba: al
     * contratar se creaban usuario y expediente, pero el candidato quedaba con user_id NULL. Se
     * perdía el rastro de "quién se postuló → quién trabaja aquí", que es el hilo que necesita el
     * reporte de reclutamiento y el que evita duplicar a alguien que vuelve a postularse.
     */
    public function test_al_contratar_el_candidato_queda_ligado_a_su_cuenta(): void
    {
        $c = $this->candidato();

        $this->contratar($c)->assertStatus(200);

        $cuenta = DB::table('users')->where('email', 'ana@candidata.test')->first();
        $this->assertNotNull($cuenta, 'la contratación tiene que crear la cuenta');
        $this->assertDatabaseHas('candidates', [
            'id' => $c->id,
            'status' => 'hired',
            'user_id' => $cuenta->id,
        ]);
    }

    public function test_contratar_avisa_de_que_falta_capturar_el_sueldo(): void
    {
        $c = $this->candidato();

        $respuesta = $this->contratar($c)->assertStatus(200);

        $this->assertTrue($respuesta->json('salary_pending'),
            'el ATS no pregunta el sueldo: si no se avisa, la nómina lo inventa en silencio');
        $this->assertNotEmpty($respuesta->json('avisos'));

        // Nada bloquea: la contratación sí ocurrió.
        $this->assertDatabaseHas('employees', ['email' => 'ana@candidata.test', 'is_active_employee' => true]);
    }

    // --- Vacante de otra empresa ---------------------------------------------------------

    public function test_no_se_puede_reasignar_al_candidato_una_vacante_de_otra_empresa(): void
    {
        $otra = Tenant::create([
            'name' => 'La Vecina 2', 'subdomain' => 'vecina2', 'public_slug' => 'vecina2',
            'plan' => 'pro', 'is_active' => true,
        ]);
        $puestoAjeno = JobRole::create([
            'tenant_id' => $otra->id, 'name' => 'Gerente Ajeno', 'area' => 'Dirección',
            'esAperturador' => true, 'tiempoTolerancia' => 5,
        ]);
        $vacanteAjena = Vacancy::create([
            'tenant_id' => $otra->id, 'job_role_id' => $puestoAjeno->id,
            'title' => 'Ajena', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);

        $c = $this->candidato();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/candidates/{$c->id}", ['applied_vacancy_id' => $vacanteAjena->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('applied_vacancy_id');

        $this->assertSame($this->vacante->id, DB::table('candidates')->where('id', $c->id)->value('applied_vacancy_id'));
    }
}
