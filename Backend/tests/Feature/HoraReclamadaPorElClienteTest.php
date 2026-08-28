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
 * La hora del cliente tiene UNA sola puerta (2026-08-27 r1, endurecido 2026-08-28 r2).
 *
 * r1 dejó visible la deriva de toda hora reclamada. r2 cerró la puerta falsa: la bandera
 * `offline_sync` + hora la ponía EL CLIENTE y ningún cliente legítimo la manda (la cola offline
 * real —offlineDb.ts— sincroniza por /clock/punch-batch con `occurredAt`). El único que la
 * mandaba era quien se quitaba retardos, así que ahora se RECHAZA de raíz, y la bandera suelta
 * tampoco compra las exenciones offline (candado de baja R89, ventana de comida R91).
 *
 * La puerta legítima (batch → `occurredAt`) conserva el candado r1: el futuro se sustituye por
 * la hora del servidor, toda hora reclamada guarda su deriva, y con más de 10 minutos el fichaje
 * cae en la bandeja de revisión del supervisor (§67.C).
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
        // NACE con su zona ya escrita.
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

    // ------------------------------------------------- r2: la puerta falsa, cerrada

    /**
     * EL FRAUDE EXACTO de r1, ahora rechazado: son las 10:00, el turno era a las 09:00, y el
     * cliente manda `offline_sync: true` con hora 09:00 para llegar "a tiempo". Nada se guarda.
     */
    public function test_offline_sync_con_hora_se_rechaza_de_raiz(): void
    {
        try {
            app(ClockService::class)->processPunch(
                $this->colaborador,
                'check_in',
                '09:00:00',
                ['offline_sync' => true]
            );
            $this->fail('la bandera del cliente con hora propia debió rechazarse');
        } catch (\Exception $e) {
            $this->assertStringContainsString('cola de sincronización', $e->getMessage());
        }

        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->count(), 'el fichaje forjado no deja fila');
    }

    /**
     * La bandera SUELTA (sin hora) tampoco compra nada: antes marcaba el ponche como "offline"
     * y con eso saltaba el candado de baja R89 — un dado de baja podía iniciar turno en vivo.
     */
    public function test_offline_sync_suelto_ya_no_compra_las_exenciones_offline(): void
    {
        Employee::where('user_id', $this->colaborador->id)->update(['is_active_employee' => false]);

        try {
            app(ClockService::class)->processPunch(
                $this->colaborador,
                'check_in',
                null,
                ['offline_sync' => true]
            );
            $this->fail('un colaborador dado de baja no inicia turno EN VIVO por declararse offline');
        } catch (\Exception $e) {
            $this->assertStringContainsString('dado de baja', $e->getMessage());
        }
    }

    // ------------------------------------- la puerta legítima (batch) conserva el candado r1

    /** La misma jugada por el batch NO se rechaza (R84) — pero cae en revisión con su deriva. */
    public function test_quitarse_el_retardo_por_el_batch_cae_en_revision(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            null,
            [],
            '2026-08-25T09:00:00Z' // occurredAt: reclama una hora antes de que el servidor lo reciba
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
            null,
            [],
            '2026-08-25T17:59:00Z' // "ya casi salgo", dice el cliente a las 10:00
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
            null,
            [],
            '2026-08-25T09:55:00Z' // se quedó sin red 5 minutos
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

    /** Y el retardo NO se pierde ni se inventa: la hora reclamada sigue mandando en el cálculo. */
    public function test_la_hora_reclamada_sigue_decidiendo_el_retardo_pero_a_la_vista(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            null,
            [],
            '2026-08-25T09:00:00Z'
        );

        $entrada = $this->ultimo();

        // El sistema respeta el momento reclamado (inmutabilidad LFT del ponche offline)…
        $this->assertFalse((bool) $entrada->is_late, 'a las 09:00 el turno empezaba: sin retardo');
        // …pero el supervisor lo tiene en su bandeja para decidir si se lo cree.
        $this->assertTrue((bool) $entrada->flagged_for_review);
    }

    // --------------------------------------------- r2, punto 1: el instante, no sólo la hora

    /**
     * Cada fichaje sella su INSTANTE UTC además de la hora local. La noche en que se retrasa el
     * reloj (America/Tijuana), la 01:30 local existe dos veces; con el instante en `details` (y
     * en la bitácora, que copia `details`) el momento nunca es ambiguo.
     */
    public function test_cada_fichaje_sella_su_instante_utc(): void
    {
        app(ClockService::class)->processPunch($this->colaborador, 'check_in');

        $detalles = json_decode($this->ultimo()->details, true);

        $this->assertSame('2026-08-25T10:00:00+00:00', $detalles['instante_utc']);
    }

    /** En el batch, el instante sellado es el del PONCHE reclamado, no el de la sincronización. */
    public function test_el_instante_del_batch_es_el_del_ponche(): void
    {
        app(ClockService::class)->processPunch(
            $this->colaborador,
            'check_in',
            null,
            [],
            '2026-08-25T09:30:00Z'
        );

        $detalles = json_decode($this->ultimo()->details, true);

        $this->assertSame('2026-08-25T09:30:00+00:00', $detalles['instante_utc']);
    }
}
