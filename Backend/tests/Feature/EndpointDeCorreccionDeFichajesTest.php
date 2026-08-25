<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Scopes\ExcludeAnuladasScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El endpoint de corrección de fichajes (Capa 3, 2026-08-25).
 *
 * Hasta hoy corregir un fichaje requería un ingeniero con acceso a la consola — la dependencia que
 * había que quitar. El controlador sólo valida y delega: la mecánica (anular sin borrar, firmar el
 * rastro, avisar al colaborador) vive en el servicio, donde ninguna pantalla puede saltársela.
 */
class EndpointDeCorreccionDeFichajesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;
    private User $supervisora;
    private JobRole $puestoDeMando;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));
        $this->tenant = Tenant::create(['name' => 'Endpoint QA', 'subdomain' => 'endpointqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->puestoDeMando = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Encargada', 'area' => 'Operaciones']);

        $this->admin = $this->persona('Jefa', 'admin');
        $this->colaborador = $this->persona('Miguel', 'empleado');
        $this->supervisora = $this->persona('Maria', 'supervisor', $this->puestoDeMando->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre, string $rol, ?int $puestoId = null): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@endpointqa.test', 'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'job_role_id' => $puestoId, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    private function fichaje(array $extra = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => '2026-08-24', 'type' => 'check_in', 'time' => '09:03:00',
            'is_late' => true, 'late_minutes' => 3,
        ], $extra));
    }

    private function corregir(User $quien, array $cuerpo)
    {
        return $this->actingAs($quien)->postJson('/api/v1/admin/punch-corrections', $cuerpo);
    }

    // ------------------------------------------------------------- seguridad

    public function test_un_colaborador_no_puede_corregir_fichajes(): void
    {
        $f = $this->fichaje();

        $this->corregir($this->colaborador, [
            'time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'Quiero quitarme el retardo.',
        ])->assertStatus(403);

        $this->assertNull($f->fresh()->anulado_at);
    }

    /**
     * `manage_punch_corrections` NO está en SUPERVISOR_DEFAULTS: mover la evidencia con la que la
     * empresa se defiende no se hereda por ser supervisor.
     */
    public function test_una_supervisora_sin_la_capacidad_recibe_403(): void
    {
        $f = $this->fichaje();

        $this->corregir($this->supervisora, [
            'time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'El reloj iba adelantado tres minutos.',
        ])->assertStatus(403);
    }

    public function test_con_la_capacidad_concedida_a_su_puesto_si_puede(): void
    {
        $f = $this->fichaje();
        $this->conceder($this->puestoDeMando->id, 'manage_punch_corrections');

        $this->corregir($this->supervisora, [
            'time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'El reloj de la sucursal iba adelantado.',
        ])->assertCreated();
    }

    public function test_no_se_puede_corregir_un_fichaje_de_otra_empresa(): void
    {
        $otro = Tenant::create(['name' => 'Otra', 'subdomain' => 'otraendpoint', 'plan' => 'basic', 'is_active' => true]);
        $ajeno = User::create([
            'tenant_id' => $otro->id, 'name' => 'Ajeno', 'email' => 'ajeno@otraendpoint.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $suyo = TimeEntry::create([
            'tenant_id' => $otro->id, 'user_id' => $ajeno->id, 'date' => '2026-08-24',
            'type' => 'check_in', 'time' => '09:00:00', 'is_late' => false, 'late_minutes' => 0,
        ]);

        $this->corregir($this->admin, [
            'time_entry_id' => $suyo->id, 'time' => '08:00', 'motivo' => 'Intento de tocar otra empresa.',
        ])->assertStatus(404);

        $this->assertNull($suyo->fresh()->anulado_at);
    }

    // ------------------------------------------------------------- el motivo

    public function test_un_motivo_de_dos_letras_no_pasa(): void
    {
        $f = $this->fichaje();

        $r = $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'ok']);

        $r->assertStatus(422);
        $this->assertStringContainsString('no explican nada', $r->json('errors.motivo.0'));
        $this->assertNull($f->fresh()->anulado_at, 'sin motivo válido no se toca nada');
    }

    public function test_sin_motivo_tampoco(): void
    {
        $f = $this->fichaje();

        $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '09:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');
    }

    // ------------------------------------------------------------- corregir

    public function test_corregir_anula_el_viejo_y_deja_el_nuevo_marcado(): void
    {
        $f = $this->fichaje();

        $r = $this->corregir($this->admin, [
            'time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'El reloj de la sucursal iba adelantado.',
        ]);

        $r->assertCreated()->assertJsonPath('success', true);

        $viejo = TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)->find($f->id);
        $this->assertNotNull($viejo->anulado_at);

        $nuevo = TimeEntry::find($r->json('nuevo_id'));
        $this->assertSame('09:00:00', substr((string) $nuevo->time, 0, 8));
        $this->assertSame(
            $r->json('correccion_id'),
            (int) $nuevo->creado_por_correccion_id,
            'el fichaje nuevo tiene que saber que nació de una corrección: de ahí sale la etiqueta'
        );
    }

    public function test_se_puede_anular_sin_sustituto(): void
    {
        $f = $this->fichaje();

        $r = $this->corregir($this->admin, [
            'time_entry_id' => $f->id, 'anular' => true, 'motivo' => 'Doble clic: este fichaje está duplicado.',
        ]);

        $r->assertCreated();
        $this->assertNull($r->json('nuevo_id'));
        $this->assertCount(0, TimeEntry::where('user_id', $this->colaborador->id)->get());
    }

    public function test_corregir_dos_veces_el_mismo_fichaje_da_422_y_no_500(): void
    {
        $f = $this->fichaje();
        $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'Primera corrección buena.'])
            ->assertCreated();

        $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '08:55', 'motivo' => 'Segunda sobre el mismo.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ese fichaje ya está anulado. Corrige el que lo sustituyó, no éste.');
    }

    public function test_dar_de_alta_un_fichaje_olvidado(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/admin/punch-corrections/alta', [
            'user_id' => $this->colaborador->id, 'date' => '2026-08-24',
            'type' => 'check_out', 'time' => '18:00',
            'motivo' => 'Olvidó checar salida; el encargado lo vio irse a las seis.',
        ]);

        $r->assertCreated();
        $this->assertSame('18:00:00', substr((string) TimeEntry::find($r->json('nuevo_id'))->time, 0, 8));
    }

    // ------------------------------------------------------------- la historia

    public function test_la_historia_devuelve_la_cadena_completa_con_sus_motivos(): void
    {
        $f = $this->fichaje();
        $r1 = $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'El reloj iba adelantado.']);
        $r2 = $this->corregir($this->admin, ['time_entry_id' => $r1->json('nuevo_id'), 'time' => '08:55', 'motivo' => 'La cámara lo muestra a las 8:55.']);

        $h = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/punch-corrections/' . $r2->json('nuevo_id') . '/historia');

        $h->assertOk();
        $this->assertSame(['09:03:00', '09:00:00', '08:55:00'], collect($h->json('fichajes'))->pluck('time')->all());
        $this->assertSame([false, false, true], collect($h->json('fichajes'))->pluck('vigente')->all());

        $motivos = collect($h->json('correcciones'))->pluck('motivo')->all();
        $this->assertSame(['El reloj iba adelantado.', 'La cámara lo muestra a las 8:55.'], $motivos);
        $this->assertSame('Jefa', $h->json('correcciones.0.autorizado_por_nombre'));
    }

    /** Leer la evidencia no es moverla: una supervisora sin la capacidad puede consultarla. */
    public function test_quien_no_puede_corregir_si_puede_ver_la_historia(): void
    {
        $f = $this->fichaje();
        $r = $this->corregir($this->admin, ['time_entry_id' => $f->id, 'time' => '09:00', 'motivo' => 'El reloj iba adelantado.']);

        $this->actingAs($this->supervisora)
            ->getJson('/api/v1/admin/punch-corrections/' . $r->json('nuevo_id') . '/historia')
            ->assertOk();
    }

    private function conceder(int $puestoId, string $capacidad): void
    {
        $permisoId = DB::table('permissions')->where('name', $capacidad)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => $capacidad, 'description' => $capacidad,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('role_permissions')->insert([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $puestoId,
            'permission_id' => $permisoId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
