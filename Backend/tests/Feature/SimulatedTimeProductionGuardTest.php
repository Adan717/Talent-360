<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seguridad (production-readiness): el MODO SIMULADO (el cliente fija la hora del ponche) se ignora
 * en PRODUCCIÓN. La asistencia es un registro legal (LFT) y anti-fraude; un admin no debe poder
 * activar `time_mode='simulated'` para fabricar horas de entrada de toda su plantilla (borrar
 * retardos, backdatear). En dev/QA (local) el simulador sigue funcionando.
 */
class SimulatedTimeProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa S', 'subdomain' => 's' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 's' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        // El admin activó el modo simulado.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => 'time_mode'],
            ['value' => json_encode('simulated'), 'created_at' => now(), 'updated_at' => now()]
        );
        // tz UTC para una comparación de hora determinista.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => 'timezone'],
            ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
        );
        return [$tenant, $user];
    }

    public function test_en_produccion_se_ignora_la_hora_del_cliente(): void
    {
        App::detectEnvironment(fn () => 'production');
        Carbon::setTestNow(Carbon::parse('2026-07-10 14:30:00')); // hora "real" del servidor
        try {
            [, $user] = $this->makeUser();

            // El cliente intenta fichar entrada a las 09:00 (borraría el retardo) pese a ser 14:30.
            app(ClockService::class)->processPunch($user, 'check_in', '09:00:00');

            $entry = DB::table('time_entries')->where('user_id', $user->id)->first();
            $this->assertSame('14:30:00', $entry->time, 'en producción debe usarse la hora del SERVIDOR, no la del cliente');
            $this->assertTrue((bool) $entry->is_late, '14:30 vs turno 09:00 es retardo: no se puede borrar con hora simulada');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_en_dev_el_simulador_si_respeta_la_hora_del_cliente(): void
    {
        // El entorno de tests es 'testing' (no production) → el simulador sigue vivo.
        Carbon::setTestNow(Carbon::parse('2026-07-10 14:30:00'));
        try {
            [, $user] = $this->makeUser();

            app(ClockService::class)->processPunch($user, 'check_in', '09:00:00');

            $entry = DB::table('time_entries')->where('user_id', $user->id)->first();
            $this->assertSame('09:00:00', $entry->time, 'en dev/QA el modo simulado respeta la hora del cliente');
        } finally {
            Carbon::setTestNow();
        }
    }
}
