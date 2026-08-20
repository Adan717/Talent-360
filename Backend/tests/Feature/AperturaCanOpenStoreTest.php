<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreOpeningHandoffService;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 46 (Reloj): `can_open_store` debe ENFORCEARSE, no ser decorativo.
 *
 * Bug cazado en la auditoría R42: el admin puede apagar "puede abrir tienda" a un colaborador desde
 * el panel (la columna se valida en `StoreOpeningController:131,189`, se castea y se guarda), pero
 * NINGÚN lookup la miraba: los tres filtraban sólo por `is_active` + `priority_order`
 * (`ClockService:150-154`, `StoreOpeningService:79-84`, `StoreOpeningHandoffService:170-176`).
 * Resultado: apagar el flag no cambiaba NADA — a esa persona le seguían entregando la apertura.
 *
 * Esto importa especialmente porque la regla de negocio del cliente es "sólo el Gerente abre la
 * tienda". Antes de esta ronda esa regla se cumplía **por accidente**: lo único que mandaba era
 * `current_responsible_employee_id`, que se fija al crear el status del día.
 */
class AperturaCanOpenStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Tenant Apertura',
            'subdomain' => 'tenant-apertura',
            'public_slug' => 'tenant-apertura',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Desalinea users.id vs employees.id (como en producción).
        User::factory()->create(['role' => 'empleado']);
    }

    private function makeEmployeeWithUser(string $name): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $user->id . '@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['user' => User::withoutGlobalScopes()->find($user->id), 'employee_id' => $employeeId];
    }

    private function makeAssignment(int $employeeId, int $priority, bool $canOpen): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'company_id' => 1,
            'store_id' => 1,
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => $canOpen,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * EL DÍA 1 DE TODA EMPRESA NUEVA: el status del día nace ANTES de que existan portadores.
     *
     * La fila del día se crea al primer vistazo de cualquiera —basta con que alguien abra el
     * Reloj— y elige responsable en ese momento. Una empresa recién dada de alta todavía no
     * tiene portadores configurados, así que nacía huérfana... y nunca los recogía.
     *
     * El síntoma no dice nada: el dial exige `currentUser.id === responsibleId` para ofrecer
     * "Abrir Tienda"; con responsable nulo eso no se cumple para NADIE, y la tienda se queda
     * cerrada todo el día mostrando "Esperando apertura por: Encargado". Le pasa a todo cliente
     * nuevo, siempre, y no hay nada en pantalla que lo explique.
     */
    public function test_el_dia_sin_responsable_adopta_al_portador_cuando_ya_existe(): void
    {
        $servicio = app(StoreOpeningService::class);

        // Primer vistazo SIN portadores: el día nace huérfano (correcto: no se elige a nadie
        // que no esté autorizado).
        $status = $servicio->getTodayOpeningStatus(1, 1);
        $this->assertNull($status->current_responsible_employee_id, 'sin portadores no debe elegirse a nadie');

        // El administrador configura la apertura DESPUÉS, que es el orden real de las cosas.
        $gerente = $this->makeEmployeeWithUser('Gerente Que Llegó Después');
        $this->makeAssignment($gerente['employee_id'], 1, true);

        $status = $servicio->getTodayOpeningStatus(1, 1);

        $this->assertSame(
            (int) $gerente['user']->id,
            (int) $status->current_responsible_employee_id,
            'el día ya existía y se quedaba sin responsable para siempre: la tienda no se podía abrir'
        );
    }

    /**
     * El caso del cliente: al Cajero (prioridad 1) se le APAGA "puede abrir tienda"; el Gerente
     * (prioridad 2) sí puede. El responsable del día debe ser el GERENTE, no el Cajero.
     */
    public function test_el_responsable_del_dia_ignora_a_quien_no_puede_abrir(): void
    {
        $cajero = $this->makeEmployeeWithUser('Cajero Sin Llave');
        $gerente = $this->makeEmployeeWithUser('Gerente Con Llave');

        $this->makeAssignment($cajero['employee_id'], 1, false); // prioridad 1 pero SIN permiso
        $this->makeAssignment($gerente['employee_id'], 2, true);

        $status = app(StoreOpeningService::class)->getTodayOpeningStatus(1, 1);

        $this->assertSame(
            (int) $gerente['user']->id,
            (int) $status->current_responsible_employee_id,
            'con can_open_store=false el Cajero NO debe recibir la apertura, aunque sea prioridad 1'
        );
    }

    /**
     * El handoff (cuando el responsable falla) tampoco debe pasarle la bolita a alguien sin permiso:
     * debe saltarse al de prioridad 2 y llegar al de prioridad 3.
     */
    public function test_el_handoff_salta_a_quien_no_puede_abrir(): void
    {
        $titular = $this->makeEmployeeWithUser('Titular');
        $sinPermiso = $this->makeEmployeeWithUser('Sin Permiso');
        $suplente = $this->makeEmployeeWithUser('Suplente Real');

        $this->makeAssignment($titular['employee_id'], 1, true);
        $this->makeAssignment($sinPermiso['employee_id'], 2, false); // debe saltarse
        $this->makeAssignment($suplente['employee_id'], 3, true);

        app(StoreOpeningService::class)->getTodayOpeningStatus(1, 1);
        app(StoreOpeningHandoffService::class)
            ->handoffToNextResponsible(1, $titular['user']->id, 'no_response', null, 1);

        // La fecha DEBE resolverse en la tz del tenant, igual que hacen el servicio al crear el
        // status (StoreOpeningService:41) y el propio handoff al releerlo
        // (StoreOpeningHandoffService:149). Con `now()` esto usaba la tz de la app (UTC) y el test
        // era una BOMBA DE TIEMPO: entre las 00:00 y las 06:00 UTC (18:00-24:00 en México) UTC ya
        // va un día por delante, así que buscaba un status de "mañana" y encontraba null.
        $status = DB::table('store_daily_opening_statuses')
            ->where('tenant_id', 1)
            ->whereDate('date', Carbon::now(app(StoreOpeningService::class)->tenantTimezone(1))->format('Y-m-d'))
            ->first();

        $this->assertSame(
            (int) $suplente['user']->id,
            (int) $status->current_responsible_employee_id,
            'el handoff debe saltarse al de prioridad 2 (can_open_store=false) y llegar al 3'
        );
    }

    /**
     * Si NADIE puede abrir, no se inventa un responsable: el status queda sin responsable en vez de
     * entregarle la apertura a alguien a quien el admin se la quitó explícitamente.
     */
    public function test_si_nadie_puede_abrir_no_se_asigna_responsable(): void
    {
        $a = $this->makeEmployeeWithUser('Nadie Uno');
        $b = $this->makeEmployeeWithUser('Nadie Dos');

        $this->makeAssignment($a['employee_id'], 1, false);
        $this->makeAssignment($b['employee_id'], 2, false);

        $status = app(StoreOpeningService::class)->getTodayOpeningStatus(1, 1);

        $this->assertNull(
            $status->current_responsible_employee_id,
            'sin nadie autorizado no debe elegirse a un no-autorizado'
        );
    }
}
