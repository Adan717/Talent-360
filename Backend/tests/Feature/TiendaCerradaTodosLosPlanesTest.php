<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R76: el bloqueo de "tienda cerrada" sólo aplicaba a plan GRATUITO (`!$isPro`). En
 * pro/enterprise el freno vivía únicamente en el frontend → quien lo saltara fichaba con la tienda
 * cerrada. Ahora aplica a todos los planes.
 *
 * Y se OMITE cuando nadie está designado para abrir: sin encargados no hay "tienda abierta" que
 * enforcear, y bloquear dejaría a todo el equipo sin fichar (callejón sin salida real: es el que
 * tumbó la prueba del usuario).
 */
class TiendaCerradaTodosLosPlanesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tenant,1:User,2:Employee} */
    private function makeTenant(string $plan): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa TC', 'subdomain' => 'tc' . uniqid(),
            'plan' => $plan, 'is_active' => true,
        ]);
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => 'timezone'],
            ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
        );
        LftSetting::create(['tenant_id' => $tenant->id, 'max_late_block_minutes' => 0]);

        [$user, $emp] = $this->makeUser($tenant->id, 'Encargado');
        return [$tenant, $user, $emp];
    }

    /** @return array{0:User,1:Employee} */
    private function makeUser(int $tenantId, string $nombre): array
    {
        $user = User::create([
            'tenant_id' => $tenantId, 'name' => $nombre,
            'email' => strtolower($nombre) . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $emp = Employee::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'name' => $nombre,
            'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        return [$user, $emp];
    }

    private function designarEncargado(int $tenantId, int $employeeId, int $prioridad = 1, bool $puedeAbrir = true): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $tenantId, 'company_id' => 1,
            // El store_id es GLOBAL desde R52: hay que usar el de ESTE tenant, no un 1 fijo.
            'store_id' => \App\Helpers\TenantStore::defaultIdFor($tenantId),
            'employee_id' => $employeeId, 'priority_order' => $prioridad,
            'can_open_store' => $puedeAbrir,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fichar(User $user): void
    {
        app(ClockService::class)->processPunch($user, 'check_in');
    }

    /** EL FIX: en enterprise, con encargado designado y tienda sin abrir, un empleado NO puede fichar. */
    public function test_en_enterprise_la_tienda_cerrada_bloquea_al_empleado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $encargado, $empEncargado] = $this->makeTenant('enterprise');
            $this->designarEncargado($tenant->id, $empEncargado->id);
            [$otro] = $this->makeUser($tenant->id, 'Cajero');

            $this->expectException(\Exception::class);
            $this->expectExceptionMessageMatches('/tienda física se encuentra cerrada/');
            $this->fichar($otro);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El ENCARGADO sí puede fichar con la tienda cerrada (es quien la abre). */
    public function test_el_encargado_si_puede_fichar_para_abrir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $encargado, $empEncargado] = $this->makeTenant('enterprise');
            $this->designarEncargado($tenant->id, $empEncargado->id);

            $this->fichar($encargado);

            $this->assertDatabaseHas('time_entries', ['user_id' => $encargado->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Con la tienda ABIERTA, cualquiera puede fichar. */
    public function test_con_la_tienda_abierta_todos_pueden_fichar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $encargado, $empEncargado] = $this->makeTenant('enterprise');
            $this->designarEncargado($tenant->id, $empEncargado->id);
            [$otro] = $this->makeUser($tenant->id, 'Cajero');

            DB::table('store_daily_opening_statuses')->insert([
                'tenant_id' => $tenant->id,
                'store_id' => \App\Helpers\TenantStore::defaultIdFor($tenant->id),
                'date' => '2026-07-10',
                // Estas tres son NOT NULL sin default en el esquema.
                'scheduled_opening_time' => '2026-07-10 09:00:00',
                'pre_opening_window_start' => '2026-07-10 08:00:00',
                'report_deadline' => '2026-07-10 09:30:00',
                'status' => 'opened', 'current_responsible_employee_id' => $encargado->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->fichar($otro);

            $this->assertDatabaseHas('time_entries', ['user_id' => $otro->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * SIN encargados designados el bloqueo se OMITE: un tenant que sólo lleva asistencia (o al que le
     * quitaron las asignaciones) no puede quedar con todo el equipo sin fichar.
     */
    public function test_sin_encargados_designados_no_se_bloquea_a_nadie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant] = $this->makeTenant('enterprise');
            [$otro] = $this->makeUser($tenant->id, 'Cajero');   // sin ninguna asignación de apertura

            $this->fichar($otro);

            $this->assertDatabaseHas('time_entries', ['user_id' => $otro->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Un encargado con `can_open_store` APAGADO no cuenta: si es el único, el gate se omite. */
    public function test_un_encargado_sin_permiso_de_abrir_no_cuenta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $encargado, $empEncargado] = $this->makeTenant('enterprise');
            $this->designarEncargado($tenant->id, $empEncargado->id, 1, puedeAbrir: false);
            [$otro] = $this->makeUser($tenant->id, 'Cajero');

            $this->fichar($otro);

            $this->assertDatabaseHas('time_entries', ['user_id' => $otro->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El plan gratuito conserva el comportamiento de siempre (bloquea con tienda cerrada). */
    public function test_freemium_sigue_bloqueando(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $encargado, $empEncargado] = $this->makeTenant('freemium');
            $this->designarEncargado($tenant->id, $empEncargado->id);
            [$otro] = $this->makeUser($tenant->id, 'Cajero');

            $this->expectException(\Exception::class);
            $this->expectExceptionMessageMatches('/tienda física se encuentra cerrada/');
            $this->fichar($otro);
        } finally {
            Carbon::setTestNow();
        }
    }
}
