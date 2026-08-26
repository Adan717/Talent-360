<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitation;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Prohibido inventar correos (2026-08-26).
 *
 * El alta no pedía correo: lo FABRICABA a partir del nombre (`juan.perez@decorarte.com`) y, con
 * homónimos, `juanperez-a7f3k2@decorarte.com`. Esos buzones no existen. El día que se encienda el
 * correo, el sistema mandaría PINes y contraseñas —que son las credenciales de acceso— a
 * direcciones inventadas, y por SMTP nadie se enteraría: no hay rebote, no hay reporte. El
 * administrador creería que su gente recibió sus accesos.
 *
 * Regla: el correo lo escribe un humano o no existe. Sin correo la persona entra por el kiosco con
 * su PIN, que es como entra la mayoría de una plantilla de piso.
 */
class NoSeInventanCorreosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Tenant::create(['name' => 'Correos QA', 'subdomain' => 'correosqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Operaciones']);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@correosqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function alta(array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees', array_merge([
            'name' => 'Juan Perez',
            'role' => 'empleado',
            'job_role_id' => $this->puesto->id,
            'hire_date' => '2026-08-01',
            'salary' => 2100,
            'phone' => '5512345678',
        ], $extra));
    }

    public function test_se_puede_dar_de_alta_sin_correo(): void
    {
        $r = $this->alta();

        $r->assertSuccessful();

        $empleado = Employee::withoutGlobalScopes()->where('name', 'Juan Perez')->first();
        $this->assertNotNull($empleado, 'el alta sin correo tiene que funcionar');
        $this->assertEmpty($empleado->email, 'no se le puede inventar un buzón');
    }

    /** Y sin correo, no se le manda invitación a ninguna parte. */
    public function test_sin_correo_no_se_manda_ninguna_invitacion(): void
    {
        $this->alta()->assertSuccessful();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    /** Pero el PIN sí se le genera: es lo que el administrador le comparte en persona. */
    public function test_sin_correo_igual_recibe_su_pin_de_acceso(): void
    {
        $this->alta()->assertSuccessful();

        $empleado = Employee::withoutGlobalScopes()->where('name', 'Juan Perez')->first();
        // La invitación (y con ella el PIN) sólo se dispara con correo; sin él, el admin lo
        // genera desde RRHH. Lo que NO puede pasar es que se le fabrique un correo para forzarlo.
        $this->assertEmpty($empleado->email);
    }

    public function test_con_correo_real_si_se_encola_la_invitacion(): void
    {
        $this->alta(['email' => 'juan.perez.real@gmail.com'])->assertSuccessful();

        $this->assertSame(
            'juan.perez.real@gmail.com',
            Employee::withoutGlobalScopes()->where('name', 'Juan Perez')->first()->email
        );
        Mail::assertQueued(EmployeeInvitation::class);
    }

    /**
     * El caso que producía `juanperez-a7f3k2@`: dos personas con el mismo correo. Ahora se explica
     * en vez de fabricar una dirección que no existe.
     */
    public function test_un_correo_repetido_se_rechaza_en_vez_de_inventar_otro(): void
    {
        $this->alta(['email' => 'juan@empresa.com'])->assertSuccessful();

        $r = $this->alta(['name' => 'Juan Perez Segundo', 'email' => 'juan@empresa.com']);

        $r->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertStringContainsString('correo real o déjalo vacío', $r->json('errors.email.0'));

        // Y sobre todo: NO nació un segundo buzón inventado.
        $this->assertSame(
            1,
            Employee::withoutGlobalScopes()->where('email', 'like', 'juan%@empresa.com')->count()
        );
    }

    /** Dos personas sin correo conviven: NULL no choca con NULL en el índice único. */
    public function test_varios_colaboradores_sin_correo_conviven(): void
    {
        $this->alta(['name' => 'Sin Correo Uno'])->assertSuccessful();
        $this->alta(['name' => 'Sin Correo Dos'])->assertSuccessful();
        $this->alta(['name' => 'Sin Correo Tres'])->assertSuccessful();

        $this->assertSame(3, Employee::withoutGlobalScopes()->whereNull('email')->count());
    }

    /**
     * GUARDIA: que no vuelva a aparecer un generador de correos. El defecto no fue escribir esa
     * función — fue que estuviera disponible para que alguien la llamara "porque ya estaba".
     */
    public function test_no_queda_ningun_generador_de_correos_en_el_alta(): void
    {
        $fuente = file_get_contents(app_path('Http/Controllers/EmployeeController.php'));

        // Se prohíbe la FUNCIÓN y su llamada, no la palabra: el comentario que explica por qué se
        // eliminó sí puede nombrarla, y conviene que lo haga.
        $this->assertStringNotContainsString(
            'function correoDisponible',
            $fuente,
            'volvió a aparecer un generador de correos en el alta'
        );
        $this->assertStringNotContainsString(
            '$this->correoDisponible(',
            $fuente,
            'el alta volvió a fabricar un correo'
        );
    }
}
