<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ronda 54 (Reloj): fundación del KIOSKO — PIN + ponche por PIN.
 *
 * Modelo del kiosko (decisión de producto 2026-07-15): una tableta compartida en la entrada. La
 * tableta se identifica con la sesión de quien la monta (gerente/admin) — de ahí sale el TENANT —;
 * el empleado se identifica con su PIN, hace UNA acción y la sesión vuelve a reposo.
 *
 * DECISIONES DE ESTA RONDA:
 *  - El PIN es PERSISTENTE y de uso diario, DISTINTO del `pin_code` de onboarding (que es de un solo
 *    uso, plaintext, y se consume a null al activar — reutilizarlo rompería el alta).
 *  - Se guarda HASHEADO (bcrypt, `kiosk_pin_hash`): el admin nunca lo ve, sólo lo resetea.
 *  - Como un hash bcrypt NO se puede consultar ("¿quién tiene el PIN 123456?"), se acompaña de un
 *    BLIND INDEX (`kiosk_pin_lookup` = HMAC-SHA256 del PIN con el APP_KEY como pepper): determinista,
 *    permite lookup O(1) y unique por tenant, y un leak SÓLO de BD no lo revierte (falta la clave).
 *  - `POST /kiosk/punch` ENFORCEA `can_clock_in` en el SERVIDOR (cierra el follow-up de R53): en una
 *    tableta compartida el gate de cliente no vale nada.
 *  - Rate-limit anti-fuerza-bruta: un PIN de 6 dígitos es de baja entropía, así que la defensa real
 *    contra adivinación online es limitar los intentos FALLIDOS, no la fuerza del hash.
 */
class KioskPinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'kiosk-uno');
        $this->seedTenant(2, 'kiosk-dos');
    }

    private function seedTenant(int $id, string $slug): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id, 'name' => 'Tenant ' . $id, 'subdomain' => $slug, 'public_slug' => $slug,
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeJobRole(int $tenantId): int
    {
        return DB::table('job_roles')->insertGetId([
            'tenant_id' => $tenantId, 'name' => 'Cajero(a)', 'area' => 'Operaciones',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Crea user + expediente enlazados, con puesto salvo que se pida lo contrario. */
    private function makeEmpleado(int $tenantId, string $accessRole = 'empleado', bool $conPuesto = true, bool $conUser = true): array
    {
        $userId = null;
        if ($conUser) {
            $user = User::factory()->create(['role' => $accessRole]);
            DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
            $userId = $user->id;
        }
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => 'Emp ' . ($userId ?? 'sin-user'),
            'email' => 'emp' . uniqid() . '@t.local',
            'job_role_id' => $conPuesto ? $this->makeJobRole($tenantId) : null,
            'is_active_employee' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [
            'employee' => Employee::withoutGlobalScopes()->find($employeeId),
            'user' => $userId ? User::withoutGlobalScopes()->find($userId) : null,
        ];
    }

    /** El host del kiosko: un usuario del tenant cuya sesión sirve de ancla de tenant. */
    private function host(int $tenantId): User
    {
        return $this->makeEmpleado($tenantId, 'admin')['user'];
    }

    // ---- Asignación del PIN (admin) -------------------------------------------------

    public function test_el_admin_asigna_un_pin_y_se_guarda_hasheado(): void
    {
        $admin = $this->host(1);
        $emp = $this->makeEmpleado(1)['employee'];

        $res = $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$emp->id}/kiosk-pin", ['pin' => '135790']);

        $res->assertStatus(200);
        $emp->refresh();
        $this->assertNotNull($emp->kiosk_pin_hash);
        $this->assertNotSame('135790', $emp->kiosk_pin_hash, 'el PIN NO se guarda en claro');
        $this->assertTrue(Hash::check('135790', $emp->kiosk_pin_hash));
        $this->assertStringNotContainsString('135790', $res->getContent(), 'la respuesta nunca devuelve el PIN');
    }

    public function test_dos_empleados_del_mismo_tenant_no_pueden_compartir_pin(): void
    {
        $admin = $this->host(1);
        $a = $this->makeEmpleado(1)['employee'];
        $b = $this->makeEmpleado(1)['employee'];

        $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$a->id}/kiosk-pin", ['pin' => '246810'])->assertStatus(200);
        $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$b->id}/kiosk-pin", ['pin' => '246810'])
            ->assertStatus(422); // colisión: el PIN no identificaría a UN solo empleado
    }

    public function test_el_mismo_pin_se_permite_en_tenants_distintos(): void
    {
        $a = $this->makeEmpleado(1)['employee'];
        $b = $this->makeEmpleado(2)['employee'];

        $this->actingAs($this->host(1))->postJson("/api/v1/admin/employees/{$a->id}/kiosk-pin", ['pin' => '135791'])->assertStatus(200);
        $this->actingAs($this->host(2))->postJson("/api/v1/admin/employees/{$b->id}/kiosk-pin", ['pin' => '135791'])->assertStatus(200);
    }

    public function test_el_admin_no_puede_ponerle_pin_a_un_empleado_de_otro_tenant(): void
    {
        $ajeno = $this->makeEmpleado(2)['employee'];

        $this->actingAs($this->host(1))->postJson("/api/v1/admin/employees/{$ajeno->id}/kiosk-pin", ['pin' => '246819'])
            ->assertStatus(404);
        $ajeno->refresh();
        $this->assertNull($ajeno->kiosk_pin_hash);
    }

    public function test_el_pin_debe_ser_de_6_digitos(): void
    {
        $admin = $this->host(1);
        $emp = $this->makeEmpleado(1)['employee'];

        foreach (['123', '1234567', 'abcdef', '12 45 6'] as $malo) {
            $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$emp->id}/kiosk-pin", ['pin' => $malo])
                ->assertStatus(422);
        }
    }

    // ---- Ponche por PIN (kiosko) ----------------------------------------------------

    public function test_ponche_con_pin_correcto_ficha_al_empleado_correcto(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '314159']);

        $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '314159', 'type' => 'check_in']);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);
        $this->assertSame($emp['employee']->id, (int) $res->json('employee.id'), 'el ponche debe identificar al dueño del PIN');
        // Se registró la entrada para SU user, no para el host.
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $emp['user']->id,
            'type' => 'check_in',
        ]);
    }

    public function test_ponche_con_pin_incorrecto_no_ficha_a_nadie(): void
    {
        $host = $this->host(1);
        $this->makeEmpleado(1); // existe alguien, pero con otro PIN (ninguno aquí)

        $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '999999', 'type' => 'check_in']);

        $res->assertStatus(422);
        $this->assertSame(0, DB::table('time_entries')->count());
        $this->assertStringNotContainsString('999999', $res->getContent());
    }

    /**
     * Aislamiento (lección transversal R52): un PIN válido en el tenant 1 NO debe fichar a nadie
     * desde el kiosko del tenant 2.
     */
    public function test_un_pin_de_otro_tenant_no_resuelve_en_este_kiosko(): void
    {
        $empT1 = $this->makeEmpleado(1);
        $this->actingAs($this->host(1))->postJson("/api/v1/admin/employees/{$empT1['employee']->id}/kiosk-pin", ['pin' => '424242']);

        // El MISMO PIN, pero el kiosko lo hospeda un usuario del tenant 2.
        $res = $this->actingAs($this->host(2))->postJson('/api/v1/kiosk/punch', ['pin' => '424242', 'type' => 'check_in']);

        $res->assertStatus(422);
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $empT1['user']->id)->count());
    }

    /**
     * EL FOLLOW-UP DE R53, cerrado en servidor: un empleado SIN puesto no puede fichar por kiosko
     * aunque tenga PIN. En la tableta compartida el gate de cliente no existe.
     */
    public function test_sin_puesto_no_ficha_aunque_tenga_pin(): void
    {
        $host = $this->host(1);
        $sinPuesto = $this->makeEmpleado(1, 'empleado', conPuesto: false);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$sinPuesto['employee']->id}/kiosk-pin", ['pin' => '505050'])->assertStatus(200);

        $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '505050', 'type' => 'check_in']);

        $res->assertStatus(403);
        $this->assertSame(0, DB::table('time_entries')->count());
    }

    /** Un expediente sin usuario vinculado (huérfano, R40) no puede fichar: no hay a quién. */
    public function test_expediente_huerfano_no_ficha(): void
    {
        $host = $this->host(1);
        $huerfano = $this->makeEmpleado(1, conUser: false);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$huerfano['employee']->id}/kiosk-pin", ['pin' => '606060'])->assertStatus(200);

        $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '606060', 'type' => 'check_in']);

        $res->assertStatus(403);
    }

    public function test_reset_del_pin_invalida_el_anterior(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '707070']);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '808080']);

        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '707070', 'type' => 'check_in'])->assertStatus(422);
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '808080', 'type' => 'check_in'])->assertStatus(200);
    }

    public function test_rate_limit_tras_varios_pin_fallidos(): void
    {
        $host = $this->host(1);

        // 5 intentos fallidos permitidos; el 6º se bloquea.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000000', 'type' => 'check_in'])
                ->assertStatus(422);
        }
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000000', 'type' => 'check_in'])
            ->assertStatus(429);
    }

    /**
     * Cazado por el code-review: `/sync/state` arma el payload con query builder (`select
     * employees.*`), donde el `$hidden` del modelo NO aplica → el hash y el blind index del PIN
     * viajaban CRUDOS a cada empleado. Misma clase de fuga que R44/R50.
     */
    public function test_sync_state_no_filtra_el_pin_de_kiosko(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '135791']);

        $res = $this->actingAs($host)->getJson('/api/v1/sync/state');

        $res->assertStatus(200);
        $this->assertStringNotContainsString('kiosk_pin_hash', $res->getContent());
        $this->assertStringNotContainsString('kiosk_pin_lookup', $res->getContent());
    }

    /**
     * Paridad con el login (R42): un empleado ARCHIVADO no ficha por kiosko, aunque conserve puesto,
     * usuario y PIN. El kiosko no pasa por login, así que esa defensa no lo cubre — se replica aquí.
     */
    public function test_empleado_archivado_no_ficha_por_kiosko(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '246800']);

        // Archivar: el flujo real pone users.is_active=false (EmployeeController).
        DB::table('users')->where('id', $emp['user']->id)->update(['is_active' => false]);

        $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '246800', 'type' => 'check_in']);

        $res->assertStatus(403);
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $emp['user']->id)->count());
    }

    /**
     * El bypass que cazó el review: un insider con UN PIN válido propio no debe poder resetear el
     * presupuesto de fallos intercalando su acierto. Un ponche correcto NO limpia el contador.
     */
    public function test_un_acierto_no_resetea_el_contador_de_fallos(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '313131']);

        // 3 fallos, luego 1 acierto (que antes limpiaba), luego 2 fallos más → el 6º total bloquea.
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000000', 'type' => 'check_in'])->assertStatus(422);
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000001', 'type' => 'check_in'])->assertStatus(422);
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000002', 'type' => 'check_in'])->assertStatus(422);
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '313131', 'type' => 'check_in']); // acierto
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000003', 'type' => 'check_in'])->assertStatus(422);
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000004', 'type' => 'check_in'])->assertStatus(422);
        // 5 fallos acumulados (el acierto intercalado NO los borró) → el 6º intento se bloquea.
        $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '000005', 'type' => 'check_in'])->assertStatus(429);
    }

    /** Un ponche correcto no debe contar para el rate-limit (mañana con 20 empleados fichando). */
    public function test_los_ponches_correctos_no_disparan_el_rate_limit(): void
    {
        $host = $this->host(1);
        $emp = $this->makeEmpleado(1);
        $this->actingAs($host)->postJson("/api/v1/admin/employees/{$emp['employee']->id}/kiosk-pin", ['pin' => '909090']);

        for ($i = 0; $i < 8; $i++) {
            // check_in / check_out alternados para que ClockService no rechace por estado repetido;
            // lo que importa es que NINGUNO devuelva 429.
            $res = $this->actingAs($host)->postJson('/api/v1/kiosk/punch', ['pin' => '909090', 'type' => 'check_in']);
            $this->assertNotSame(429, $res->status(), "el intento $i correcto no debe ser rate-limited");
        }
    }
}
