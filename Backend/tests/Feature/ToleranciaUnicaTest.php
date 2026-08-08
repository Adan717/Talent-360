<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una sola tolerancia: la que se paga (2026-08-08).
 *
 * El reloj del colaborador pintaba su ventana de puntualidad con
 * `timeBankConfigs.maxLateMinsAllowed`, que `TenantInitializationService` sembraba en **15**
 * para toda empresa nueva. El SERVIDOR, en cambio, decide `is_late` con
 * `lft_settings.late_tolerance_minutes`, que es **10** por defecto y 10 en las empresas
 * reales de la instancia de pruebas.
 *
 * De fábrica y sin que nadie configurara nada mal: quien llegaba a las 09:12 con turno de
 * 09:00 veía en su reloj que seguía DENTRO de la tolerancia, fichaba tranquilo, y el
 * servidor le anotaba **retardo de 12 minutos** — que se acumula hacia faltas y descuentos
 * de nómina. Familia H21: el frontend calculando una cosa y el backend otra.
 */
class ToleranciaUnicaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tolerancia QA', 'subdomain' => 'tolqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 15,
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Puntual',
            'email' => 'puntual@tolqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Puntual', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00',
        ]);
    }

    private function toleranciaQueVeElReloj(): ?int
    {
        $estado = $this->actingAs($this->colaborador)
            ->getJson('/api/v1/sync/state')
            ->assertOk()
            ->json();

        return $estado['system_settings']['timeBankConfigs']['maxLateMinsAllowed'] ?? null;
    }

    /** La empresa nace con las dos tolerancias iguales. */
    public function test_una_empresa_nueva_nace_con_una_sola_tolerancia(): void
    {
        $delServidor = DB::table('lft_settings')->where('tenant_id', $this->tenant->id)
            ->value('late_tolerance_minutes') ?? 10;

        $this->assertSame((int) $delServidor, $this->toleranciaQueVeElReloj(),
            'el dial no puede prometer una ventana distinta de la que el servidor va a juzgar');
    }

    /** Si el dueño cambia la tolerancia en LFT, el reloj la respeta. */
    public function test_el_reloj_sigue_la_tolerancia_que_configura_el_dueno(): void
    {
        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 20, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->assertSame(20, $this->toleranciaQueVeElReloj());
    }

    /** Aunque quede una configuración vieja con 15, manda la del servidor. */
    public function test_una_configuracion_vieja_desalineada_ya_no_manda(): void
    {
        // Así estaban las empresas creadas antes de este arreglo.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'timeBankConfigs'],
            ['value' => json_encode(['maxLateMinsAllowed' => 15]), 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->assertSame(10, $this->toleranciaQueVeElReloj(),
            'los datos viejos no pueden seguir mintiéndole al colaborador');
    }

    /**
     * El caso que costaba dinero, de punta a punta: llega 12 minutos tarde y lo que el reloj
     * le promete coincide con lo que el servidor le apunta.
     */
    public function test_a_los_doce_minutos_el_reloj_y_el_servidor_dicen_lo_mismo(): void
    {
        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('store_logs')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'type' => 'open',
            'date' => now()->timezone('America/Mexico_City')->toDateString(), 'time' => '08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $hoy = now()->timezone('America/Mexico_City')->toDateString();
        Carbon::setTestNow(Carbon::parse("{$hoy} 09:12:00", 'America/Mexico_City'));

        $tolerancia = $this->toleranciaQueVeElReloj();

        $this->actingAs($this->colaborador)->postJson('/api/v1/clock/punch', [
            'user_id' => $this->colaborador->id,
            'type' => 'check_in',
        ]);

        $fichaje = DB::table('time_entries')->latest('id')->first(['is_late', 'late_minutes']);

        // 12 > 10: el servidor lo marca tarde Y el reloj ya lo anunciaba (12 > tolerancia).
        $this->assertTrue((bool) $fichaje->is_late);
        $this->assertGreaterThan($tolerancia, 12,
            'si el reloj promete más de 12 min de gracia, le está mintiendo a quien va a cobrar menos');

        Carbon::setTestNow();
    }
}
