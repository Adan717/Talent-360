<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El reloj no le cree al cliente sin dejar rastro (2026-08-27, punto 2 de la revisión externa).
 *
 * El protocolo offline es legítimo: un ponche encolado sin red ocurrió en su momento real y la
 * LFT exige conservarlo (R84). Pero la bandera `offline_sync` la pone EL CLIENTE, así que
 * cualquiera podía mandar `offline_sync: true` con la hora que le conviniera y quitarse un
 * retardo — sin dejar más rastro que un `created_at` que nadie leía.
 *
 * La regla nueva no rechaza (rechazar rompería la sincronización legítima y borraría días
 * trabajados): hace visible. El futuro es imposible y se sustituye por la hora del servidor;
 * toda hora reclamada guarda su deriva; y con deriva mayor a 10 minutos el fichaje cae en la
 * bandeja de revisión del supervisor, que ya existía (§67.C).
 */
class HoraReclamadaPorElClienteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 10:00 en la zona del tenant (UTC en pruebas): el turno de 09:00 ya empezó.
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $this->tenant = Tenant::create(['name' => 'Reclamada QA', 'subdomain' => 'reclamadaqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        // updateOrInsert y no insert: desde el punto 1 de esta misma revisión, toda empresa
        // NACE con su zona ya escrita — este choque de índice único fue la confirmación en vivo.
        \Illuminate\Support\Facades\DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'timezone'],
            ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
        );

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@reclamadaqa.test',
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

    private function ultimo(): TimeEntry
    {
        return TimeEntry::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
    }

    /**
     * EL FRAUDE EXACTO: son las 10:00, el turno era a las 09:00, y el cliente manda
     * `offline_sync: true` con hora 09:00 para llegar "a tiempo". Ya no pasa en silencio.
     */
    public function test_quitarse_el_retardo_con_offline_sync_cae_en_revision(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            '09:00:00',
            ['offline_sync' => true]
        );

        $entrada = $this->ultimo();
        $detalles = json_decode($entrada->details, true);

        $this->assertTrue((bool) $entrada->flagged_for_review, 'una hora del cliente con 60 min de deriva va a revisión');
        $this->assertSame('09:00:00', $detalles['hora_reclamada']);
        $this->assertSame('10:00:00', $detalles['recibido_a'], 'el servidor deja escrito cuándo lo recibió de verdad');
        $this->assertSame(60, $detalles['deriva_min']);
    }

    /** Una hora reclamada EN EL FUTURO es imposible: se usa la del servidor y queda anotado. */
    public function test_una_hora_futura_se_sustituye_por_la_del_servidor(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            '17:59:00', // "ya casi salgo", dice el cliente a las 10:00
            ['offline_sync' => true]
        );

        $entrada = $this->ultimo();
        $detalles = json_decode($entrada->details, true);

        $this->assertSame('10:00:00', substr((string) $entrada->time, 0, 8), 'el futuro no se registra');
        $this->assertSame('17:59:00', $detalles['hora_futura_rechazada'], 'lo que el cliente pretendía queda escrito');
    }

    /** El corte de red legítimo y corto no genera ruido: deriva chica, sin bandera. */
    public function test_una_deriva_chica_no_va_a_revision(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            '09:55:00', // se quedó sin red 5 minutos
            ['offline_sync' => true]
        );

        $entrada = $this->ultimo();
        $detalles = json_decode($entrada->details, true);

        $this->assertFalse((bool) $entrada->flagged_for_review);
        $this->assertSame(5, $detalles['deriva_min'], 'la deriva queda escrita aunque no dispare revisión');
    }

    /** El ponche normal —sin protocolo offline— no carga nada de esto. */
    public function test_el_punch_en_linea_no_lleva_deriva_ni_bandera(): void
    {
        app(ClockService::class)->processPunch($this->colaborador, 'check_in');

        $entrada = $this->ultimo();
        $detalles = json_decode($entrada->details, true) ?: [];

        $this->assertFalse((bool) $entrada->flagged_for_review);
        $this->assertArrayNotHasKey('hora_reclamada', $detalles);
        $this->assertSame('10:00:00', substr((string) $entrada->time, 0, 8), 'la hora la pone el servidor');
    }

    /** El batch offline (`occurredAt`) entra por la misma aduana. */
    public function test_el_batch_offline_tambien_guarda_su_deriva(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            null,
            [],
            '2026-08-25T09:00:00Z' // occurredAt: una hora antes de que el servidor lo reciba
        );

        $entrada = $this->ultimo();
        $detalles = json_decode($entrada->details, true);

        $this->assertSame(60, $detalles['deriva_min']);
        $this->assertTrue((bool) $entrada->flagged_for_review);
    }

    /** Y el retardo NO se pierde ni se inventa: la hora reclamada sigue mandando en el cálculo. */
    public function test_la_hora_reclamada_sigue_decidiendo_el_retardo_pero_a_la_vista(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            '09:00:00',
            ['offline_sync' => true]
        );

        $entrada = $this->ultimo();

        // El sistema respeta el momento reclamado (inmutabilidad LFT del ponche offline)…
        $this->assertFalse((bool) $entrada->is_late, 'a las 09:00 el turno empezaba: sin retardo');
        // …pero el supervisor lo tiene en su bandeja para decidir si se lo cree.
        $this->assertTrue((bool) $entrada->flagged_for_review);
    }
}
