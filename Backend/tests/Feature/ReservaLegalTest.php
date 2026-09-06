<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RESERVA LEGAL: "a esta persona no la toca la purga" (2026-09-05).
 *
 * La regla —cinco años de retención, salvo juicio abierto— existía sólo como comentario en la
 * cabecera de la bitácora inmutable y en el RFC. Estas pruebas fijan la figura: quién puede
 * ponerla, qué exige, a quién alcanza, y —la más importante— que **no se pueda levantar sin
 * querer**. Levantar una reserva por accidente deja expuesta a la purga a la persona con la que la
 * empresa está en juicio; en México la prueba que el patrón destruyó se presume en su contra
 * (LFT 784 y 804).
 */
class ReservaLegalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reserva QA', 'subdomain' => 'reservaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = $this->persona('Jefa', 'admin')['user'];
    }

    /** @return array{user:User,employee:Employee} */
    private function persona(string $nombre, string $rol = 'empleado', array $extra = []): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@reservaqa.test',
            'password' => bcrypt('x'),
            'role' => $rol,
            'is_active' => true,
        ]);

        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => $nombre,
            'is_active_employee' => true,
            'base_salary' => 3000,
        ], $extra));

        return ['user' => $user, 'employee' => $employee];
    }

    // ------------------------------------------------------------------ marcar

    public function test_el_admin_marca_la_reserva_y_queda_escrita_con_su_motivo(): void
    {
        $victor = $this->persona('Victor')['employee'];

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal", [
                'motivo' => 'Demanda laboral 421/2026 en la JCA 5',
                'referencia' => 'JCA-5/421-2026',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $victor->refresh();
        $this->assertNotNull($victor->legal_hold_at, 'la reserva tiene que quedar puesta');
        $this->assertSame('Demanda laboral 421/2026 en la JCA 5', $victor->legal_hold_reason);
        $this->assertSame('JCA-5/421-2026', $victor->legal_hold_reference);
        $this->assertSame($this->admin->id, (int) $victor->legal_hold_by);
        // La foto del nombre: dentro de tres años el id puede apuntar a una ficha ya anonimizada.
        $this->assertSame('Jefa', $victor->legal_hold_by_name);
    }

    public function test_sin_motivo_no_hay_reserva(): void
    {
        $victor = $this->persona('Victor')['employee'];

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal", [])
            ->assertStatus(422);

        $this->assertNull($victor->refresh()->legal_hold_at);
    }

    public function test_alcanza_a_los_expedientes_archivados_que_es_donde_vive_quien_ya_se_fue(): void
    {
        // Quien tiene un juicio abierto casi nunca sigue en la plantilla: está archivado
        // (soft delete), que es exactamente la población que esta figura protege.
        $exempleado = $this->persona('Exempleado', 'empleado', ['is_active_employee' => false])['employee'];
        $exempleado->delete();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$exempleado->id}/reserva-legal", [
                'motivo' => 'Juicio abierto tras su salida',
            ])
            ->assertOk();

        $this->assertNotNull(
            Employee::withTrashed()->find($exempleado->id)->legal_hold_at,
            'un expediente archivado tiene que poder recibir reserva legal'
        );
    }

    // ------------------------------------------------------------------ levantar

    public function test_levantarla_tambien_exige_motivo(): void
    {
        $victor = $this->persona('Victor')['employee'];
        $victor->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Demanda'])->save();

        // Levantar es EL ACTO PELIGROSO: vuelve a exponer a esa persona al borrado. Si sólo se
        // pidiera motivo al ponerla, lo barato sería lo que hace daño.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal/levantar", [])
            ->assertStatus(422);

        $this->assertNotNull($victor->refresh()->legal_hold_at, 'la reserva no puede caerse sin motivo');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal/levantar", [
                'motivo' => 'Convenio firmado ante la junta, expediente cerrado',
            ])
            ->assertOk();

        $this->assertNull($victor->refresh()->legal_hold_at);
    }

    public function test_los_dos_actos_quedan_en_la_bitacora_de_seguridad(): void
    {
        $victor = $this->persona('Victor')['employee'];

        $this->actingAs($this->admin)->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal", [
            'motivo' => 'Demanda laboral 421/2026',
        ])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal/levantar", [
            'motivo' => 'Convenio firmado ante la junta',
        ])->assertOk();

        $eventos = DB::table('saas_audit_logs')
            ->where('tenant_id', $this->tenant->id)
            ->pluck('description', 'event_type');

        $this->assertArrayHasKey('legal_hold_puesta', $eventos->all());
        $this->assertArrayHasKey('legal_hold_levantada', $eventos->all());
        // La reserva se borra del expediente; lo único que queda de ella es este renglón, así que
        // el motivo viejo tiene que viajar dentro del texto.
        $this->assertStringContainsString('Demanda laboral 421/2026', $eventos['legal_hold_levantada']);
        $this->assertStringContainsString('Convenio firmado', $eventos['legal_hold_levantada']);
    }

    // ------------------------------------------------------------------ candados

    public function test_un_put_normal_al_expediente_no_toca_la_reserva(): void
    {
        // La pantalla de RRHH manda el expediente ENTERO en cada guardado. Si estas columnas
        // fueran asignables en masa, corregirle el teléfono a alguien —o que un supervisor
        // mandara el campo a mano— levantaría la reserva de quien tiene un juicio abierto.
        $victor = $this->persona('Victor')['employee'];
        $victor->forceFill([
            'legal_hold_at' => now()->subDay(),
            'legal_hold_reason' => 'Demanda laboral 421/2026',
            'legal_hold_by' => $this->admin->id,
            'legal_hold_by_name' => 'Jefa',
        ])->save();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$victor->id}", [
                'name' => 'Victor Corregido',
                'phone' => '5511223344',
                'legal_hold_at' => null,
                'legal_hold_reason' => null,
                'legal_hold_by' => null,
                'legal_hold_by_name' => null,
                'legal_hold_reference' => null,
                'purged_at' => now()->toDateTimeString(),
            ])
            ->assertOk();

        $victor->refresh();
        $this->assertSame('Victor Corregido', $victor->name, 'el guardado normal sí debe guardar lo normal');
        $this->assertNotNull($victor->legal_hold_at, 'un PUT normal NO puede levantar la reserva');
        $this->assertSame('Demanda laboral 421/2026', $victor->legal_hold_reason);
        $this->assertNull($victor->purged_at, 'ni marcar a alguien como ya purgado');
    }

    public function test_un_supervisor_no_puede_poner_ni_levantar_la_reserva(): void
    {
        $supervisor = $this->persona('Supervisora', 'supervisor')['user'];
        $victor = $this->persona('Victor')['employee'];

        $this->actingAs($supervisor)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal", ['motivo' => 'Lo que sea'])
            ->assertStatus(403);

        $victor->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Demanda'])->save();

        $this->actingAs($supervisor)
            ->postJson("/api/v1/admin/employees/{$victor->id}/reserva-legal/levantar", ['motivo' => 'Lo que sea'])
            ->assertStatus(403);

        $this->assertNotNull($victor->refresh()->legal_hold_at);
    }

    public function test_es_una_capacidad_indelegable_del_catalogo(): void
    {
        // Si se pudiera otorgar por puesto, el dueño podría delegar sin querer la llave que deja
        // a su empresa sin evidencia. Va donde manage_permissions y por la misma razón.
        $this->assertArrayHasKey('manage_legal_hold', \App\Support\PermissionCatalog::INDELEGABLE);
        $this->assertArrayNotHasKey('manage_legal_hold', \App\Support\PermissionCatalog::DELEGABLE);
    }

    public function test_no_se_ve_ni_se_toca_la_reserva_de_otra_empresa(): void
    {
        $otra = Tenant::create([
            'name' => 'Otra', 'subdomain' => 'otrareserva',
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $ajeno = Employee::create([
            'tenant_id' => $otra->id, 'name' => 'Ajeno', 'is_active_employee' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/employees/{$ajeno->id}/reserva-legal", ['motivo' => 'Intento cruzado'])
            ->assertStatus(404);

        $this->assertNull($ajeno->refresh()->legal_hold_at);
    }

    public function test_la_lista_muestra_las_reservas_puestas_incluidas_las_archivadas(): void
    {
        $activo = $this->persona('Activo')['employee'];
        $activo->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Demanda A'])->save();

        $archivado = $this->persona('Archivado', 'empleado', ['is_active_employee' => false])['employee'];
        $archivado->forceFill(['legal_hold_at' => now(), 'legal_hold_reason' => 'Demanda B'])->save();
        $archivado->delete();

        $this->persona('Sin reserva');

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/reserva-legal')->assertOk();

        $ids = collect($res->json('reservas'))->pluck('id')->all();
        $this->assertContains($activo->id, $ids);
        $this->assertContains($archivado->id, $ids, 'el archivado es justo el caso que importa');
        $this->assertCount(2, $ids);
    }
}
