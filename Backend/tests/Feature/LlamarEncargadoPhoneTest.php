<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R100: botón "📞 Llamar a Encargado de Llaves" (spec §3/§5 — el ÚLTIMO ítem del spec v2
 * sin implementar).
 *
 * Spec §5: "Exclusivo para titulares o suplentes de llaves cuando la tienda está cerrada. Abre el
 * marcador nativo para llamar al responsable de abrir (obteniendo el teléfono mediante
 * `responsibleUser.phone`)".
 *
 * Diseño anti-teatro: el gate es SERVER-SIDE. `GET /store-opening/today` sólo incluye
 * `responsible_phone` si el REQUESTER es portador de llaves (fila ACTIVA en
 * store_opening_assignments). El FE muestra el botón únicamente si el dato llegó → un empleado
 * común jamás recibe el teléfono (no es sólo que no vea el botón: el dato no viaja).
 *
 * OJO con los dos espacios de ID (trampa documentada en R50): `assignments.employee_id` es FK a
 * **employees**, mientras `current_responsible_employee_id` es FK a **users**. El teléfono vive en
 * el EXPEDIENTE (`employees.phone`) del responsable → hay que resolver users.id → employees.phone.
 */
class LlamarEncargadoPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'Tenant 1', 'subdomain' => 'tenant-phone',
            'public_slug' => 'tenant-phone', 'plan' => 'enterprise', 'max_users' => 20,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Desalinea users.id vs employees.id (lección R50): si coincidieran, un casamiento
        // incorrecto de espacios de ID pasaría el test por accidente.
        User::factory()->create(['role' => 'empleado']);
        User::factory()->create(['role' => 'empleado']);
    }

    private function makeEmployeeWithUser(string $name, ?string $phone = null): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $user->id . '@t.local',
            'phone' => $phone,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [
            'user' => User::withoutGlobalScopes()->find($user->id),
            'employee_id' => $employeeId,
        ];
    }

    private function makeAssignment(int $employeeId, int $priority, bool $isActive = true): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'company_id' => 1,
            'store_id' => \App\Helpers\TenantStore::defaultIdFor(1),
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => true,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_portador_de_llaves_recibe_el_telefono_del_responsable(): void
    {
        // Anclado ANTES de que se abra la ventana de apertura, para que el titular siga siendo
        // el responsable. Sin esto la prueba dependía de la hora real del reloj: corriendo por la
        // tarde el plazo ya había vencido, la cesión automática pasaba la apertura al suplente
        // —que es justo quien consulta— y entonces no hay teléfono que darle, porque el
        // responsable es él mismo. Antes pasaba igual, pero sólo porque el servicio pisaba lo que
        // el handoff escribía; al dejar de pisarlo, la cesión ocurre de verdad.
        $tz = \App\Helpers\TenantTimezone::for(1);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse(\Carbon\Carbon::now($tz)->toDateString() . ' 06:00:00', $tz));

        $titular = $this->makeEmployeeWithUser('Titular Llaves', '55-1234-5678');
        $suplente = $this->makeEmployeeWithUser('Suplente Llaves', '55-9999-0000');
        $this->makeAssignment($titular['employee_id'], 1);
        $this->makeAssignment($suplente['employee_id'], 2);

        $response = $this->actingAs($suplente['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertSame(
            '55-1234-5678',
            $response->json('responsible_phone'),
            'el suplente (portador de llaves) debe recibir el teléfono del expediente del responsable'
        );

        \Carbon\Carbon::setTestNow();
    }

    public function test_empleado_comun_no_recibe_telefono(): void
    {
        $titular = $this->makeEmployeeWithUser('Titular Llaves', '55-1234-5678');
        $comun = $this->makeEmployeeWithUser('Empleado Comun', '55-7777-7777');
        $this->makeAssignment($titular['employee_id'], 1);
        // El común NO tiene asignación de apertura → no es portador de llaves.

        $response = $this->actingAs($comun['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('responsible_phone'),
            'el gate es server-side: a un empleado común el teléfono NO le viaja'
        );
    }

    public function test_asignacion_inactiva_no_es_portador(): void
    {
        $titular = $this->makeEmployeeWithUser('Titular Llaves', '55-1234-5678');
        $exSuplente = $this->makeEmployeeWithUser('Ex Suplente', null);
        $this->makeAssignment($titular['employee_id'], 1);
        $this->makeAssignment($exSuplente['employee_id'], 2, false); // desactivada

        $response = $this->actingAs($exSuplente['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNull($response->json('responsible_phone'));
    }

    public function test_el_propio_responsable_no_recibe_su_telefono(): void
    {
        $titular = $this->makeEmployeeWithUser('Titular Llaves', '55-1234-5678');
        $this->makeAssignment($titular['employee_id'], 1);

        $response = $this->actingAs($titular['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('responsible_phone'),
            'si el responsable eres tú, no hay a quién llamar'
        );
    }

    public function test_responsable_sin_telefono_da_null(): void
    {
        $titular = $this->makeEmployeeWithUser('Titular Sin Cel', null); // expediente sin phone
        $suplente = $this->makeEmployeeWithUser('Suplente Llaves', '55-9999-0000');
        $this->makeAssignment($titular['employee_id'], 1);
        $this->makeAssignment($suplente['employee_id'], 2);

        $response = $this->actingAs($suplente['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('responsible_phone'),
            'sin teléfono en el expediente del responsable → null (el FE no muestra el botón)'
        );
    }
}
