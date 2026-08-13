<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bloque 2 / A2 (2026-08-13): tolerancia de retardo con precedencia PUESTO > EMPRESA.
 *
 * La fuente por puesto es `role_clock_policies.config.tolerancia_retardo_mins` (matriz §65):
 * el dial YA la pintaba desde la línea del jefe, pero el servidor juzgaba sólo con
 * `lft_settings` — la misma familia de mentira que H21/ToleranciaUnica, ahora por puesto.
 * Estas pruebas fallan sin la conexión server-side.
 */
class ToleranciaPorPuestoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Puestos QA', 'subdomain' => 'puestosqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gerente', 'area' => 'Piso',
            'esAperturador' => false,
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gerenta',
            'email' => 'gerenta@puestosqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Gerenta', 'job_role_id' => $this->puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00',
        ]);

        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'created_at' => now(), 'updated_at' => now()]
        );

        // La tienda abierta, para que el check_in entre por el camino normal.
        DB::table('store_logs')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'type' => 'open',
            'date' => now()->timezone('America/Mexico_City')->toDateString(), 'time' => '08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ficharALas(string $hora): object
    {
        $hoy = now()->timezone('America/Mexico_City')->toDateString();
        Carbon::setTestNow(Carbon::parse("{$hoy} {$hora}", 'America/Mexico_City'));

        $this->actingAs($this->colaborador)->postJson('/api/v1/clock/punch', [
            'user_id' => $this->colaborador->id,
            'type' => 'check_in',
        ]);

        Carbon::setTestNow();

        return DB::table('time_entries')->latest('id')->first(['is_late', 'late_minutes', 'details']);
    }

    private function darlePoliticaAlPuesto(array $config): void
    {
        DB::table('role_clock_policies')->insert([
            'tenant_id' => $this->tenant->id,
            'job_role_id' => $this->puesto->id,
            'policy_name' => 'Gerente',
            'config' => json_encode($config),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Sin política del puesto, manda la empresa (10): a los 12 minutos hay retardo. */
    public function test_sin_politica_del_puesto_manda_la_empresa(): void
    {
        $fichaje = $this->ficharALas('09:12:00');

        $this->assertTrue((bool) $fichaje->is_late);

        $details = json_decode($fichaje->details, true);
        $this->assertSame(10, $details['tolerancia_aplicada']);
        $this->assertSame('empresa', $details['tolerancia_origen']);
    }

    /** Con política del puesto (15), el MISMO minuto 12 ya no es retardo: el puesto manda. */
    public function test_la_tolerancia_del_puesto_le_gana_a_la_de_la_empresa(): void
    {
        $this->darlePoliticaAlPuesto(['tolerancia_retardo_mins' => 15]);

        $fichaje = $this->ficharALas('09:12:00');

        $this->assertFalse((bool) $fichaje->is_late,
            'el dial promete la tolerancia del puesto; el servidor tiene que juzgar con la misma');

        $details = json_decode($fichaje->details, true);
        $this->assertSame(15, $details['tolerancia_aplicada']);
        $this->assertSame('puesto', $details['tolerancia_origen']);
    }

    /** Una política sin la llave de tolerancia NO opina: cae a la empresa. */
    public function test_una_politica_sin_tolerancia_cae_a_la_empresa(): void
    {
        $this->darlePoliticaAlPuesto(['paseDeLista' => true]);

        [$minutos, $origen] = ClockService::toleranciaDeRetardo($this->tenant->id, $this->puesto->id);

        $this->assertSame(10, $minutos);
        $this->assertSame('empresa', $origen);
    }

    /**
     * Sólo hacia adelante: un fichaje ya juzgado conserva su veredicto aunque el puesto
     * cambie de tolerancia después — nada recalcula lo pasado.
     */
    public function test_cambiar_la_tolerancia_no_reescribe_fichajes_pasados(): void
    {
        $fichaje = $this->ficharALas('09:12:00');
        $this->assertTrue((bool) $fichaje->is_late);
        $id = DB::table('time_entries')->latest('id')->value('id');

        $this->darlePoliticaAlPuesto(['tolerancia_retardo_mins' => 30]);

        $despues = DB::table('time_entries')->find($id, ['is_late', 'late_minutes']);
        $this->assertTrue((bool) $despues->is_late, 'el pasado se juzgó con la regla de su momento');
    }
}
