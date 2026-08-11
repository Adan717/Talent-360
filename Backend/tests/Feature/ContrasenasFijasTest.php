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
 * Ninguna cuenta nace con una contraseña escrita en el código.
 *
 * El alta de RRHH ya se cerró (`AltaDeColaboradorTest`), pero quedaban CUATRO puertas más que
 * creaban usuarios con la misma cadena fija — y dos de ellas dan acceso de mando:
 *
 *  1. Contratar a un candidato del ATS (`password123`).
 *  2. Los 5 colaboradores de ejemplo del asistente de arranque (`password123`), que nacen
 *     DENTRO de la empresa real del cliente.
 *  3. El ADMIN de una empresa creada desde la consola de plataforma (`password123`): con sólo
 *     saber su correo se entraba a ver toda la nómina de esa empresa.
 *  4. Ascender a alguien a admin/supervisor desde la ficha, cuando aún no tenía cuenta.
 *
 * Cada caso comprueba además que la contraseña resultante es DISTINTA entre personas: una
 * aleatoria compartida sería el mismo defecto con otro nombre.
 */
class ContrasenasFijasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Claves QA', 'subdomain' => 'clavesqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@clavesqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    // --- 1. Contratar a un candidato del ATS -------------------------------------------

    public function test_contratar_a_un_candidato_no_le_da_la_contrasena_de_siempre(): void
    {
        $vacanteId = DB::table('vacancies')->insertGetId([
            'job_role_id' => $this->puesto->id, 'title' => 'Cajero',
            'description' => 'x', 'requirements' => 'x', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $candidatoId = DB::table('candidates')->insertGetId([
            'applied_vacancy_id' => $vacanteId, 'tenant_id' => $this->tenant->id,
            'name' => 'Ana Contratada', 'email' => 'ana.contratada@clavesqa.test',
            'status' => 'evaluation', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/candidates/{$candidatoId}", ['status' => 'hired'])
            // Respondía 500 SIEMPRE: se le asignaba `pin_code` al candidato y esa columna no
            // existe en su tabla. El expediente quedaba creado pero el candidato nunca pasaba
            // a "contratado", así que el reintento repetía el error para siempre.
            ->assertStatus(200);

        $this->assertSame('hired', DB::table('candidates')->where('id', $candidatoId)->value('status'));
        $this->assertNotEmpty($respuesta->json('pin_code'), 'el PIN de invitación es lo que la persona necesita para activarse');

        $hash = DB::table('users')->where('email', 'ana.contratada@clavesqa.test')->value('password');

        $this->assertNotNull($hash, 'contratar debe crear la cuenta');
        $this->assertFalse(Hash::check('password123', $hash),
            'quien es contratado entra a la empresa: no puede nacer con la contraseña publicada en el código');
    }

    // --- 2. Los 5 de ejemplo del asistente de arranque ---------------------------------

    public function test_los_colaboradores_de_ejemplo_no_comparten_la_contrasena_de_siempre(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/onboarding/inject-demo', [
                'inject_roles' => true,
                'inject_employees' => true,
            ])
            ->assertStatus(200);

        $hashes = DB::table('users')
            ->where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->admin->id)
            ->pluck('password');

        $this->assertGreaterThanOrEqual(5, $hashes->count(), 'el asistente siembra 5 personas de ejemplo');

        foreach ($hashes as $hash) {
            $this->assertFalse(Hash::check('password123', $hash),
                'los de ejemplo nacen dentro de la empresa REAL: una contraseña conocida son cinco puertas abiertas');
        }

        $this->assertCount($hashes->count(), $hashes->unique(),
            'tampoco pueden compartir una misma aleatoria entre ellos');
    }

    // --- 3. El admin de una empresa creada desde la consola ----------------------------

    public function test_el_admin_de_una_empresa_nueva_no_nace_con_la_contrasena_de_siempre(): void
    {
        $platformAdmin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Plataforma',
            'email' => 'plataforma@talent360.test', 'password' => bcrypt('x'),
            'role' => 'platform_admin', 'is_active' => true,
        ]);

        $nueva = Tenant::create([
            'name' => 'Empresa Nueva', 'subdomain' => 'nueva',
            'plan' => 'pro', 'max_users' => 10, 'is_active' => true,
        ]);

        $respuesta = $this->actingAs($platformAdmin)
            ->putJson("/api/v1/platform/tenants/{$nueva->id}/update-profile", [
                'name' => 'Empresa Nueva', 'plan' => 'pro', 'max_users' => 10,
                'admin_name' => 'Dueño Nuevo', 'admin_email' => 'dueno@nueva.test',
                // sin admin_password: es el caso en que el servidor la elegía
            ])
            ->assertStatus(200);

        $generada = $respuesta->json('admin_password_generada');
        $this->assertNotEmpty($generada, 'si el servidor la genera, tiene que devolverla UNA vez o la cuenta queda inalcanzable');

        $hash = DB::table('users')->where('email', 'dueno@nueva.test')->value('password');
        $this->assertFalse(Hash::check('password123', $hash),
            'el admin ve toda la nómina de su empresa: con la contraseña fija bastaba saber su correo');
        $this->assertTrue(Hash::check($generada, $hash),
            'la que se muestra en pantalla tiene que ser la que abre la cuenta');
    }

    public function test_si_la_consola_manda_contrasena_esa_se_respeta_y_no_se_devuelve(): void
    {
        $platformAdmin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Plataforma',
            'email' => 'plataforma2@talent360.test', 'password' => bcrypt('x'),
            'role' => 'platform_admin', 'is_active' => true,
        ]);

        $nueva = Tenant::create([
            'name' => 'Otra Empresa', 'subdomain' => 'otra',
            'plan' => 'pro', 'max_users' => 10, 'is_active' => true,
        ]);

        $respuesta = $this->actingAs($platformAdmin)
            ->putJson("/api/v1/platform/tenants/{$nueva->id}/update-profile", [
                'name' => 'Otra Empresa', 'plan' => 'pro', 'max_users' => 10,
                'admin_name' => 'Dueña Otra', 'admin_email' => 'duena@otra.test',
                'admin_password' => 'laQueYoElijo9',
            ])
            ->assertStatus(200);

        $this->assertNull($respuesta->json('admin_password_generada'),
            'no se inventa nada cuando la eligió un humano');

        $hash = DB::table('users')->where('email', 'duena@otra.test')->value('password');
        $this->assertTrue(Hash::check('laQueYoElijo9', $hash));
    }

    // --- 4. Ascender a alguien a mando desde la ficha ----------------------------------

    public function test_ascender_a_supervisor_no_le_crea_la_cuenta_con_la_contrasena_de_siempre(): void
    {
        // Expediente SIN cuenta (los hay: altas viejas y expedientes importados).
        $ficha = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => null,
            'name' => 'Sin Cuenta', 'email' => 'sincuenta@clavesqa.test',
            'job_role_id' => $this->puesto->id, 'is_active_employee' => true,
            'salary' => 3000, 'base_salary' => 3000,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$ficha->id}", [
                'name' => 'Sin Cuenta', 'email' => 'sincuenta@clavesqa.test',
                'role' => 'supervisor',
            ])
            ->assertStatus(200);

        $hash = DB::table('users')->where('email', 'sincuenta@clavesqa.test')->value('password');

        $this->assertNotNull($hash, 'ascender a mando crea la cuenta');
        $this->assertFalse(Hash::check('password123', $hash),
            'y era una cuenta de SUPERVISOR la que nacía con la contraseña publicada');
    }
}
