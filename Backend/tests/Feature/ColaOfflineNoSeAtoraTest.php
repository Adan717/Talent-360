<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La cola offline no se atora por un ítem malo (2026-08-28, r2b).
 *
 * Al cerrar la puerta falsa de `offline_sync`, /clock/punch-batch quedó como ÚNICA superficie
 * donde el cliente pone la hora — y ahí apareció una píldora venenosa: la credencial del ítem se
 * exigía a nivel REQUEST (`required_without`), así que un solo ponche sin firma (uno legado, o
 * encolado sin el secreto en caché) devolvía 422 y tumbaba el lote ENTERO. La cola se congelaba
 * para siempre: los ponches buenos nunca llegaban y vencían a los 7 días. El frontend agravaba
 * el cuadro mandando `offline_stamp: ''` (que para Laravel es ausente) y no descartando nunca lo
 * rechazado, así que reintentaba el mismo lote roto en cada reconexión.
 *
 * Regla nueva: la credencial se valida POR ÍTEM. El malo se rechaza solo; el resto sincroniza.
 */
class ColaOfflineNoSeAtoraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-25 14:00:00'));

        $this->tenant = Tenant::create(['name' => 'Cola QA', 'subdomain' => 'colaqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@colaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function batch(array $punches)
    {
        Sanctum::actingAs($this->colaborador);

        return $this->postJson('/api/v1/clock/punch-batch', ['punches' => $punches]);
    }

    /** EL DEFECTO: un ítem sin firma ya NO tumba el lote — se rechaza solo y el bueno entra. */
    public function test_un_item_sin_credencial_no_tumba_el_lote(): void
    {
        $res = $this->batch([
            // El de la píldora: exactamente lo que mandaba el frontend, `offline_stamp: ''`.
            ['type' => 'check_in', 'time' => '09:00:00', 'offline_stamp' => '', 'client_timestamp' => '2026-08-25T09:00:00Z'],
            // El bueno, con su client_stamp de idempotencia.
            ['type' => 'check_out', 'time' => '13:00:00', 'client_stamp' => 'stamp-bueno-001', 'client_timestamp' => '2026-08-25T13:00:00Z'],
        ]);

        $res->assertStatus(200);

        $resultados = collect($res->json('results'))->keyBy('index');
        $this->assertSame('rejected', $resultados[0]['status'], 'el ítem sin credencial se rechaza SOLO');
        $this->assertSame('missing_stamp', $resultados[0]['reason']);
        $this->assertSame('recorded', $resultados[1]['status'], 'el ponche legítimo del mismo lote SÍ entra');

        $this->assertSame(1, TimeEntry::withoutGlobalScopes()->count());
    }

    /** Y sin el campo siquiera (como lo manda ya el frontend): mismo trato, sin 422. */
    public function test_un_item_sin_el_campo_tampoco_tumba_el_lote(): void
    {
        $res = $this->batch([
            ['type' => 'check_in', 'time' => '09:00:00', 'client_timestamp' => '2026-08-25T09:00:00Z'],
            ['type' => 'check_in', 'time' => '09:05:00', 'client_stamp' => 'stamp-bueno-002', 'client_timestamp' => '2026-08-25T09:05:00Z'],
        ]);

        $res->assertStatus(200);
        $resultados = collect($res->json('results'))->keyBy('index');
        $this->assertSame('missing_stamp', $resultados[0]['reason']);
        $this->assertSame('recorded', $resultados[1]['status']);
    }

    /** La idempotencia sigue viva: reenviar el lote no duplica (respuesta perdida en la red). */
    public function test_reenviar_el_mismo_lote_no_duplica(): void
    {
        $punch = [['type' => 'check_in', 'time' => '09:00:00', 'client_stamp' => 'stamp-idem-001', 'client_timestamp' => '2026-08-25T09:00:00Z']];

        $this->batch($punch)->assertStatus(200);
        $segundo = $this->batch($punch);

        $this->assertSame('duplicate', $segundo->json('results.0.status'));
        $this->assertSame(1, TimeEntry::withoutGlobalScopes()->count(), 'una sola fila, no dos');
    }

    /** El ponche del batch sella su instante y su deriva: sigue siendo la puerta vigilada. */
    public function test_el_ponche_del_batch_conserva_su_candado_de_deriva(): void
    {
        $this->batch([[
            'type' => 'check_in', 'time' => '09:00:00',
            'client_stamp' => 'stamp-deriva-001',
            'client_timestamp' => '2026-08-25T09:00:00Z', // 5 horas antes de las 14:00 del servidor
        ]])->assertStatus(200);

        $entrada = TimeEntry::withoutGlobalScopes()->firstOrFail();
        $detalles = json_decode($entrada->details, true);

        $this->assertTrue((bool) $entrada->flagged_for_review);
        $this->assertSame(300, $detalles['deriva_min']);
        $this->assertSame('2026-08-25T09:00:00+00:00', $detalles['instante_utc']);
    }

    /** Un ítem del batch no puede declararse del Simulador para esquivar el candado. */
    public function test_el_batch_ignora_la_bandera_de_simulador(): void
    {
        $this->batch([[
            'type' => 'check_in', 'time' => '09:00:00',
            'client_stamp' => 'stamp-sim-001',
            'client_timestamp' => '2026-08-25T09:00:00Z',
            'details' => ['is_simulator' => true],
        ]])->assertStatus(200);

        $entrada = TimeEntry::withoutGlobalScopes()->firstOrFail();

        $this->assertNull($entrada->simulation_session_id, 'un ponche offline real nunca es del simulador');
        $this->assertTrue((bool) $entrada->flagged_for_review, 'y por tanto no esquiva el candado de deriva');
    }
}
