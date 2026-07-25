<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /clock/punch (TimeEntryController) no normalizaba la hora antes de delegar a processPunch,
 * así que un cliente que mandara "H:i" (sin segundos) reventaba en modo simulado
 * (createFromFormat('Y-m-d H:i:s', ...) lanza). sync sí normalizaba; punch no. Ahora
 * processPunch normaliza el simTime (pad de segundos; formato irreconocible → hora del
 * servidor), cubriendo ambos caminos.
 */
class PunchTimeNormalizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_accepts_hi_time_without_seconds_in_simulated_mode(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = Tenant::create([
                'name' => 'Empresa Punch', 'subdomain' => 'punch' . uniqid(),
                'plan' => 'enterprise', 'is_active' => true,
            ]);
            $user = User::create([
                'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'p' . uniqid() . '@t.local',
                'password' => bcrypt('password'), 'role' => 'empleado',
            ]);
            Employee::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
                'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60,
                'is_active_employee' => true,
            ]);
            DB::table('system_settings')->insert([
                ['tenant_id' => $tenant->id, 'key' => 'time_mode', 'value' => json_encode('simulated'), 'created_at' => now(), 'updated_at' => now()],
                ['tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Hora sin segundos ("09:00"): antes reventaba con 400 en modo simulado.
            $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'check_in',
                'time' => '09:00',
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
