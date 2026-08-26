<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Homónimos y organigrama (2026-08-08).
 *
 * HOMÓNIMOS: el formulario de alta no pide correo, lo genera del nombre. Con dos "Juan Pérez"
 * salía el mismo `juanperez@empresa.com` y el alta del segundo PISABA el expediente del
 * primero: su puesto, su sueldo y su horario quedaban reemplazados, y la empresa se quedaba
 * con una sola ficha para dos personas.
 *
 * ORGANIGRAMA: `updateReportTo` valida, detecta ciclos y responde 200 "Jerarquía actualizada
 * correctamente"... sobre una columna que NO EXISTÍA. Ninguna migración creó
 * `employees.report_to` y tampoco estaba en `$fillable`, así que Eloquent descartaba el
 * `update` en silencio y al recargar el organigrama volvía a salir plano.
 */
class HomonimosYOrganigramaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $cajero;
    private JobRole $almacen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Homonimos QA', 'subdomain' => 'homoqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->cajero = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);
        $this->almacen = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Almacén', 'area' => 'Bodega',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@homoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function darDeAlta(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees', array_merge([
            'name' => 'Juan Perez',
            // (2026-08-26) Antes este correo lo FABRICABA el formulario a partir del nombre. Ya
            // no: aquí se manda a propósito para reproducir el choque de dos personas a las que
            // el administrador les teclea el mismo correo.
            'email' => 'juanperez@homoqa.test',
            'role' => 'empleado',
            'job_role_id' => $this->cajero->id,
            'hire_date' => now()->toDateString(),
            'salary' => 3000,
        ], $cambios));
    }

    // --- Homónimos ---------------------------------------------------------------------

    /**
     * LA PROTECCIÓN ORIGINAL SE CONSERVA: el segundo Juan Pérez no pisa al primero. Lo que cambió
     * (2026-08-26) es la VÍA: antes se le fabricaba `juanperez2@homoqa.test` —un buzón que no
     * existe, al que luego se le mandaría su PIN de acceso—; ahora se da de alta sin correo, que
     * es lo normal en una plantilla de piso: entra con su PIN en el kiosco.
     */
    public function test_el_segundo_juan_perez_no_pisa_al_primero(): void
    {
        $this->darDeAlta()->assertStatus(201);
        $primero = Employee::first();

        // El segundo Juan Pérez: sin correo, otro puesto y otro sueldo.
        $this->darDeAlta([
            'email' => null,
            'job_role_id' => $this->almacen->id,
            'salary' => 5000,
        ])->assertStatus(201);

        $this->assertSame(2, Employee::count(), 'son dos personas, no una');

        $primero->refresh();
        $this->assertSame($this->cajero->id, $primero->job_role_id, 'al primero no se le tocó el puesto');
        $this->assertEquals(3000, $primero->base_salary, 'ni el sueldo');
    }

    /**
     * (2026-08-26) Esta prueba fijaba que al homónimo se le FABRICARA `juanperez2@homoqa.test`.
     * Esa regla se derogó: un buzón inventado no le llega a nadie, y ahí es donde después se le
     * mandaba su PIN. Ahora el choque se explica y quien da de alta decide.
     */
    public function test_repetir_el_correo_se_explica_en_vez_de_inventar_otro(): void
    {
        $this->darDeAlta()->assertStatus(201);

        $r = $this->darDeAlta();

        $r->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertStringContainsString('correo real o déjalo vacío', $r->json('errors.email.0'));

        $this->assertSame(1, Employee::count(), 'no nació un segundo expediente con un buzón falso');
    }

    /** Tres homónimos conviven: uno con correo y los demás sin él (NULL no choca con NULL). */
    public function test_tres_homonimos_conviven_sin_inventarles_buzon(): void
    {
        $this->darDeAlta()->assertStatus(201);
        $this->darDeAlta(['email' => null])->assertStatus(201);
        $this->darDeAlta(['email' => null])->assertStatus(201);

        $this->assertSame(
            ['juanperez@homoqa.test', null, null],
            Employee::orderBy('id')->pluck('email')->all()
        );
    }

    /** Si el admin confirma que es la MISMA persona, sí se actualiza su ficha. */
    public function test_confirmando_que_es_la_misma_persona_se_actualiza(): void
    {
        $this->darDeAlta()->assertStatus(201);
        $ficha = Employee::first();

        $this->darDeAlta([
            'actualizar_existente' => true,
            'job_role_id' => $this->almacen->id,
            'salary' => 5000,
        ])->assertOk();

        $this->assertSame(1, Employee::count(), 'es la misma persona: no se duplica');
        $this->assertSame($this->almacen->id, $ficha->refresh()->job_role_id);
    }

    // --- Organigrama -------------------------------------------------------------------

    private function colaborador(string $nombre, string $correo): Employee
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre, 'email' => $correo,
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'name' => $nombre,
            'email' => $correo, 'job_role_id' => $this->cajero->id, 'is_active_employee' => true,
        ]);
    }

    public function test_asignar_jefe_ahora_se_guarda_de_verdad(): void
    {
        $jefe = $this->colaborador('Gerente', 'gerente@homoqa.test');
        $subordinado = $this->colaborador('Cajero', 'cajero@homoqa.test');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$subordinado->id}/report-to", ['report_to' => $jefe->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        // La prueba de fuego: antes el endpoint respondía 200 y no escribía NADA.
        $this->assertSame($jefe->id, (int) DB::table('employees')->where('id', $subordinado->id)->value('report_to'));
    }

    public function test_se_puede_quitar_el_jefe(): void
    {
        $jefe = $this->colaborador('Gerente', 'gerente@homoqa.test');
        $subordinado = $this->colaborador('Cajero', 'cajero@homoqa.test');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$subordinado->id}/report-to", ['report_to' => $jefe->id])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$subordinado->id}/report-to", ['report_to' => null])
            ->assertOk();

        $this->assertNull(DB::table('employees')->where('id', $subordinado->id)->value('report_to'));
    }

    public function test_nadie_es_jefe_de_si_mismo(): void
    {
        $emp = $this->colaborador('Solo', 'solo@homoqa.test');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$emp->id}/report-to", ['report_to' => $emp->id])
            ->assertStatus(422);

        $this->assertNull(DB::table('employees')->where('id', $emp->id)->value('report_to'));
    }

    public function test_no_se_puede_armar_un_ciclo(): void
    {
        $jefe = $this->colaborador('Gerente', 'gerente@homoqa.test');
        $subordinado = $this->colaborador('Cajero', 'cajero@homoqa.test');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$subordinado->id}/report-to", ['report_to' => $jefe->id])
            ->assertOk();

        // Ahora el jefe intentaría reportarle a su propio subordinado.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$jefe->id}/report-to", ['report_to' => $subordinado->id])
            ->assertStatus(422);

        $this->assertNull(DB::table('employees')->where('id', $jefe->id)->value('report_to'));
    }

    public function test_el_jefe_no_puede_ser_de_otra_empresa(): void
    {
        $emp = $this->colaborador('Cajero', 'cajero@homoqa.test');

        $otra = Tenant::create([
            'name' => 'Otra', 'subdomain' => 'otrahomo', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $ajeno = Employee::create([
            'tenant_id' => $otra->id, 'name' => 'Ajeno', 'is_active_employee' => true,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$emp->id}/report-to", ['report_to' => $ajeno->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('report_to');
    }

    /** Si se borra al jefe, quien le reportaba queda sin jefe — no se borra con él. */
    public function test_borrar_al_jefe_no_arrastra_a_su_equipo(): void
    {
        $jefe = $this->colaborador('Gerente', 'gerente@homoqa.test');
        $subordinado = $this->colaborador('Cajero', 'cajero@homoqa.test');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$subordinado->id}/report-to", ['report_to' => $jefe->id])
            ->assertOk();

        DB::table('employees')->where('id', $jefe->id)->delete();

        $this->assertDatabaseHas('employees', ['id' => $subordinado->id]);
        $this->assertNull(DB::table('employees')->where('id', $subordinado->id)->value('report_to'));
    }
}
