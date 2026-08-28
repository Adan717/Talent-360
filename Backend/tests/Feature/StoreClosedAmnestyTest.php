<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R90 (Fase 4 / T4.2): "Reportar Tienda Cerrada" concede amnistía REAL.
 *
 * Antes era teatro: `reportStoreStillClosed` volteaba el status y prometía amnistía, pero no escribía
 * ninguna señal que `processPunch`/nómina leyeran (`enable_amnesty_if_store_closed` muerta). Ahora el
 * reporte marca `late_amnesty_granted` en la fila del día, y processPunch amnistía (is_late=false) al
 * PRIMER check_in que caiga dentro de la tolerancia de la apertura REAL, cuando la tienda abrió TARDE.
 *
 * No-gameable: exige reporte real (voltea el status del equipo) + que la tienda efectivamente abriera
 * tarde. Como el gate de tienda-cerrada obliga a fichar tras abrir y el reporte es previo, basta
 * punch-time (sin caso retroactivo).
 */
class StoreClosedAmnestyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-15';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DATE . ' 09:30:00'));
    }

    private function makeTenantAndEmployee(string $tz = 'UTC', string $shiftStart = '09:00:00'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Amnistía', 'subdomain' => 'amn' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode($tz), 'created_at' => now(), 'updated_at' => now()]
            );
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado', 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => $shiftStart, 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);
        return [$tenant, $user];
    }

    /** Fila de status del día. Sin assignments → el gate de tienda-cerrada no aplica (check_in libre). */
    private function makeStatus(Tenant $tenant, array $overrides = []): void
    {
        $fila = array_merge([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1, 'date' => self::DATE,
            'scheduled_opening_time' => '09:00:00', 'pre_opening_window_start' => '08:45:00',
            'report_deadline' => '09:15:00', 'status' => 'opened',
            'late_amnesty_granted' => false, 'opened_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);

        // El horario de la EMPRESA tiene que decir lo mismo que la fila del día. Si no, el
        // servicio resincroniza el día con el horario configurado —que es justo lo que debe
        // hacer cuando el admin corrige la hora— y esta fila armada a mano se descartaría.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => 'storeSchedule'],
            ['value' => json_encode([
                'openTime' => substr($fila['scheduled_opening_time'], 0, 5),
                'closeTime' => '21:00',
            ]), 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('store_daily_opening_statuses')->insert($fila);
    }

    private function checkIn(User $user): TimeEntry
    {
        app(ClockService::class)->processPunch($user, 'check_in');
        return TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->latest('id')->first();
    }

    public function test_amnistia_cuando_reportado_y_abrio_tarde(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Reportada cerrada + abrió TARDE a las 09:30 (turno 09:00). El empleado ficha a las 09:30.
        $this->makeStatus($tenant, ['late_amnesty_granted' => true, 'opened_at' => self::DATE . ' 09:30:00']);

        $entry = $this->checkIn($user);

        $this->assertFalse((bool) $entry->is_late, 'debería estar amnistiado');
        $this->assertSame(0, (int) $entry->late_minutes);
        $details = json_decode($entry->details, true);
        $this->assertTrue($details['amnesty_applied'] ?? false);
        $this->assertSame('09:00:00', $details['hora_llegada_virtual'] ?? null);
    }

    public function test_sin_reporte_no_amnistia(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Abrió tarde pero NADIE reportó → retardo normal.
        $this->makeStatus($tenant, ['late_amnesty_granted' => false, 'opened_at' => self::DATE . ' 09:30:00']);

        $entry = $this->checkIn($user);

        $this->assertTrue((bool) $entry->is_late, 'sin reporte no hay amnistía');
        $this->assertGreaterThan(0, (int) $entry->late_minutes);
    }

    public function test_abrio_a_tiempo_no_amnistia(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Reportado pero la tienda abrió A TIEMPO (09:00): el retardo de las 09:30 es del empleado.
        $this->makeStatus($tenant, ['late_amnesty_granted' => true, 'opened_at' => self::DATE . ' 09:00:00']);

        $entry = $this->checkIn($user);

        $this->assertTrue((bool) $entry->is_late);
        $this->assertGreaterThan(0, (int) $entry->late_minutes);
    }

    public function test_check_in_muy_despues_de_abrir_no_amnistia(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Abrió tarde a las 09:00... digamos abrió 09:05, pero el empleado ficha 09:30 (25 min después
        // de abrir, > tolerancia 10) → su propio retardo, no amnistía.
        $this->makeStatus($tenant, ['late_amnesty_granted' => true, 'opened_at' => self::DATE . ' 09:05:00']);

        $entry = $this->checkIn($user);

        $this->assertTrue((bool) $entry->is_late);
    }

    public function test_reporte_setea_el_flag(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Aún cerrada (pending) y ya pasó la apertura (09:00 < now 09:30).
        $this->makeStatus($tenant, ['status' => 'pending']);

        $res = app(StoreOpeningService::class)->reportStoreStillClosed($user->id, 1);

        $this->assertTrue($res['success']);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => $tenant->id, 'date' => self::DATE,
            'status' => 'closed_reported_by_employees', 'late_amnesty_granted' => true,
        ]);
    }

    public function test_reporte_rechazado_si_deshabilitado(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        $this->makeStatus($tenant, ['status' => 'pending']);
        DB::table('store_opening_settings')->insert([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1,
            'allow_store_closed_report' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Exception::class);
        app(StoreOpeningService::class)->reportStoreStillClosed($user->id, 1);
    }

    public function test_reporte_antes_de_apertura_rechazado(): void
    {
        [$tenant, $user] = $this->makeTenantAndEmployee();
        // Antes de la hora oficial: apertura programada 10:00 > now 09:30.
        $this->makeStatus($tenant, ['status' => 'pending', 'scheduled_opening_time' => '10:00:00']);

        $this->expectException(\Exception::class);
        app(StoreOpeningService::class)->reportStoreStillClosed($user->id, 1);
    }

    public function test_toggle_amnistia_off_reporta_pero_no_perdona(): void
    {
        // R90 (fix del review #1): conecta la columna muerta `enable_amnesty_if_store_closed`. Con la
        // amnistía DESACTIVADA, el reporte se registra (status volteado) pero NO concede amnistía →
        // opción "reportar sí, amnistiar no".
        [$tenant, $user] = $this->makeTenantAndEmployee();
        $this->makeStatus($tenant, ['status' => 'pending']);
        DB::table('store_opening_settings')->insert([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1,
            'allow_store_closed_report' => true, 'enable_amnesty_if_store_closed' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = app(StoreOpeningService::class)->reportStoreStillClosed($user->id, 1);

        $this->assertTrue($res['success']);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => $tenant->id, 'date' => self::DATE,
            'status' => 'closed_reported_by_employees', 'late_amnesty_granted' => false,
        ]);
    }

    public function test_amnistia_respeta_tz_no_utc(): void
    {
        // Tenant en America/Mexico_City (UTC-6): `opened_at` se guarda en UTC (Carbon::now()) y el código
        // compara en hora LOCAL. Apertura programada 09:00 local; abre tarde 09:30 local (= 15:30 UTC);
        // el empleado ficha 09:30 local. Sin manejo correcto de tz, un victim quedaría marcado tarde.
        [$tenant, $user] = $this->makeTenantAndEmployee('America/Mexico_City', '09:00:00');
        $this->makeStatus($tenant, [
            'late_amnesty_granted' => true,
            'opened_at' => self::DATE . ' 15:30:00', // 09:30 local, en UTC
        ]);
        Carbon::setTestNow(Carbon::parse(self::DATE . ' 15:30:00')); // 09:30 local

        $entry = $this->checkIn($user);

        $this->assertFalse((bool) $entry->is_late, 'la amnistía debe evaluarse en hora local, no UTC');
        $details = json_decode($entry->details, true);
        $this->assertTrue($details['amnesty_applied'] ?? false);
    }

    public function test_shift_antes_de_apertura_tambien_amnistiado(): void
    {
        // Decisión DELIBERADA (review R90 #2): la amnistía de tienda cerrada se ancla a la APERTURA real,
        // no al `shiftStart`. Un empleado con turno ANTES de la apertura (p.ej. prep) que quedó fuera por
        // la tienda cerrada también se amnistía cuando abre tarde. El admin que no quiera esta
        // generosidad apaga `enable_amnesty_if_store_closed` (ver test anterior).
        [$tenant, $user] = $this->makeTenantAndEmployee('UTC', '07:00:00');
        $this->makeStatus($tenant, ['late_amnesty_granted' => true, 'opened_at' => self::DATE . ' 09:30:00']);

        $entry = $this->checkIn($user);

        $this->assertFalse((bool) $entry->is_late);
    }
}
