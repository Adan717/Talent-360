<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 53 (Reloj): "puede fichar" se decide por el PUESTO, no por el rol de acceso.
 *
 * Decisión de producto (2026-07-15, del jefe del usuario): los roles son **dos ejes
 * independientes** — acceso a la plataforma (qué módulos ves) ⊥ puesto de trabajo (reglas
 * operativas). Fichar es operativo, así que depende del puesto: **puede fichar quien TIENE
 * expediente con puesto**, sea admin, supervisor o empleado.
 *
 * CORRECCIÓN DE LA PREMISA (verificado en vivo, 2026-07-17): el plan estratégico dice que "un
 * admin/gerente NO puede fichar". Es **falso**: la consola tiene un módulo "Reloj Checador"
 * (`App.tsx:741`) que renderiza el mismo componente, el backend ya acepta su ponche
 * (`/clock/punch` está en el grupo `role:empleado,employee,admin,supervisor,platform_admin`), y su
 * payload de auth ya trae puesto y jornada desde R41/R45. Lo ÚNICO cierto es que la ruta dedicada
 * `/empleado` rebota a quien no sea `system_role === 'empleado'` (`ProtectedRoute:23`).
 *
 * Por qué se arregla igual: esa ruta a pantalla completa es la que usará el **Kiosko** (tableta
 * compartida), y ahí el gate no puede ser el rol de acceso — quien se identifique en la tableta
 * ficha si tiene puesto. `can_clock_in` lo calcula el SERVIDOR y viaja en el payload de auth; el
 * cliente no lo decide (mismo criterio que la geocerca de R33/R35).
 */
class PuedeFicharTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tenants')->insertOrIgnore([
            'id' => 7,
            'name' => 'Tenant Fichar',
            'subdomain' => 'tenant-fichar',
            'public_slug' => 'tenant-fichar',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $systemRole): User
    {
        $user = User::factory()->create(['role' => $systemRole]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 7]);
        return User::withoutGlobalScopes()->find($user->id);
    }

    private function makeJobRole(string $name = 'Gerente de Sucursal'): int
    {
        return DB::table('job_roles')->insertGetId([
            'tenant_id' => 7,
            'name' => $name,
            'area' => 'Operaciones', // NOT NULL
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeExpediente(User $user, ?int $jobRoleId): void
    {
        DB::table('employees')->insert([
            'tenant_id' => 7,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => 'exp' . $user->id . '@t.local',
            'job_role_id' => $jobRoleId,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * EL CASO QUE DA NOMBRE A LA RONDA: un admin con expediente y puesto SÍ puede fichar. El eje de
     * acceso ('admin') no tiene nada que decir aquí.
     */
    public function test_un_admin_con_puesto_puede_fichar(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeExpediente($admin, $this->makeJobRole());

        $this->assertTrue($admin->canClockIn());
        $this->assertTrue($admin->toAuthPayload()['can_clock_in']);
    }

    public function test_un_empleado_con_puesto_puede_fichar(): void
    {
        $empleado = $this->makeUser('empleado');
        $this->makeExpediente($empleado, $this->makeJobRole('Cajero(a)'));

        $this->assertTrue($empleado->canClockIn());
    }

    /**
     * Sin expediente NO se ficha, aunque el rol de acceso sea 'empleado': la jornada, la tolerancia
     * y el employee_id de las asignaciones viven en el expediente (R32/R45). Es el caso de una
     * cuenta puramente administrativa.
     */
    public function test_sin_expediente_no_puede_fichar(): void
    {
        $soloConsola = $this->makeUser('admin');
        $huerfano = $this->makeUser('empleado');

        $this->assertFalse($soloConsola->canClockIn());
        $this->assertFalse($huerfano->canClockIn(), 'el rol de acceso NO basta: sin expediente no hay jornada');
        $this->assertFalse($soloConsola->toAuthPayload()['can_clock_in']);
    }

    /**
     * Expediente SIN puesto tampoco: sin puesto no hay política de tolerancia que aplicar (R41).
     */
    public function test_expediente_sin_puesto_no_puede_fichar(): void
    {
        $user = $this->makeUser('empleado');
        $this->makeExpediente($user, null);

        $this->assertFalse($user->canClockIn());
    }

    /**
     * El contrato con el frontend: `/login` y `/me` deben traer el flag, porque el gate de la ruta
     * `/empleado` lo lee de `currentUser`. Si no viajara, el gate rebotaría a todo el mundo.
     */
    public function test_login_y_me_devuelven_can_clock_in(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeExpediente($admin, $this->makeJobRole());

        $me = $this->actingAs($admin)->getJson('/api/v1/me');
        $me->assertStatus(200);
        $this->assertTrue($me->json('user.can_clock_in'), '/me debe traer el flag (anidado bajo `user`)');

        $login = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);
        $login->assertStatus(200);
        $this->assertTrue($login->json('user.can_clock_in'), '/login debe traer el flag');
    }

    /**
     * Cazado por el code-review de R53: `updateProfile` devolvía el modelo CRUDO, y sus consumidores
     * (`MyAccountModal:133`, `OnboardingWizard:221,348`) hacen `setCurrentUser(res.data.user)` —
     * REEMPLAZO TOTAL, no spread. Consecuencia real: un admin editaba su nombre desde la consola y
     * perdía `can_clock_in` de la sesión → dejaba de poder entrar a `/empleado` hasta recargar. No se
     * autocuraba: `useAppStore` sólo repide `/me` si el rol es 'Loading'.
     */
    public function test_update_profile_no_pierde_el_flag_ni_el_puesto(): void
    {
        $admin = $this->makeUser('admin');
        $jobRoleId = $this->makeJobRole();
        $this->makeExpediente($admin, $jobRoleId);

        $res = $this->actingAs($admin)->postJson('/api/v1/me/update-profile', [
            'name' => 'Admin Renombrado',
        ]);

        $res->assertStatus(200);
        $this->assertTrue($res->json('user.can_clock_in'), 'la respuesta debe conservar el flag: el front REEMPLAZA currentUser con esto');
        $this->assertSame($jobRoleId, $res->json('user.job_role_id'), 'y el puesto del expediente (deuda de R41)');
    }

    /**
     * El expediente NO debe filtrarse dentro del payload de auth (trae email y datos internos). Es la
     * trampa de resolverlo con `setRelation`: `toArray()` serializa las relaciones cargadas.
     */
    public function test_el_payload_de_auth_no_filtra_el_expediente_anidado(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeExpediente($admin, $this->makeJobRole());

        $payload = $admin->toAuthPayload();

        $this->assertArrayNotHasKey('employee', $payload);
        $this->assertTrue($payload['can_clock_in']);
    }
}
