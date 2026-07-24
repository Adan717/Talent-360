<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regla de Amnistía Condicionada (spec modulo_reloj_checador.md:26-30):
 * el "Perdón" por apertura tardía es INDIVIDUAL y aplica EXCLUSIVAMENTE a quienes
 * marcaron "Ya llegué" (registro `waiting`) DENTRO de su ventana de tolerancia. Quien
 * marca pasado el minuto de tolerancia puede ser validado por el gerente, pero el
 * sistema SÍ le aplica su retardo.
 *
 * Antes, processPunch daba amnistía si existía CUALQUIER `waiting` (sin mirar la hora), y
 * además confiaba en un flag `has_amnesty` mandado por el cliente.
 */
class AmnestyWindowTest extends TestCase
{
    use RefreshDatabase;

    /** hora de entrada por defecto (user->shiftStart null => '09:00:00'), tolerancia 10 => deadline 09:10 */
    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Amnistia',
            'subdomain' => 'amnesty',
            'plan' => 'enterprise', // isPro => sin bloqueo de tienda cerrada
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador',
            'email' => 'colab.amnesty@t.local',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador',
            'base_salary' => 3000.00,
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        // tz UTC para el tenant → la hora simulada (setTestNow, tz app = UTC) es la local.
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'timezone',
            'value' => json_encode('UTC'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [$tenant, $user];
    }

    private function insertWaiting(int $tenantId, int $userId, string $date, string $time): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'date' => $date,
            'type' => 'waiting',
            'time' => $time,
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function checkIn(User $user): TimeEntry
    {
        app(ClockService::class)->processPunch($user, 'check_in');
        return TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->firstOrFail();
    }

    public function test_amnesty_granted_when_waiting_within_tolerance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00')); // check_in tarde
        try {
            [$tenant, $user] = $this->makeSetup();
            // "Ya llegué" a las 09:05, DENTRO de la tolerancia (deadline 09:10).
            $this->insertWaiting($tenant->id, $user->id, '2026-07-10', '09:05:00');

            $entry = $this->checkIn($user);

            $this->assertFalse((bool) $entry->is_late, 'con waiting dentro de tolerancia debe haber amnistía');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_amnesty_when_waiting_after_tolerance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            // "Ya llegué" a las 09:20, PASADA la tolerancia (deadline 09:10): sin amnistía.
            $this->insertWaiting($tenant->id, $user->id, '2026-07-10', '09:20:00');

            $entry = $this->checkIn($user);

            $this->assertTrue((bool) $entry->is_late, 'un waiting tardío NO debe dar amnistía');
            $this->assertGreaterThan(0, (int) $entry->late_minutes);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_amnesty_when_waiting_too_early(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            // "Ya llegué" a las 08:00, ANTES de la ventana (08:50-09:10): fuera de rango,
            // sin amnistía (spec: "si lo hizo dentro de su rango").
            $this->insertWaiting($tenant->id, $user->id, '2026-07-10', '08:00:00');

            $entry = $this->checkIn($user);

            $this->assertTrue((bool) $entry->is_late, 'un waiting demasiado temprano queda fuera de rango: sin amnistía');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_amnesty_without_waiting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup();

            $entry = $this->checkIn($user);

            $this->assertTrue((bool) $entry->is_late, 'sin waiting, un check_in tardío es retardo normal');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_client_amnesty_flag_is_ignored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup();

            // El cliente afirma amnistía sin ningún waiting: el servidor debe ignorarlo.
            app(ClockService::class)->processPunch($user, 'check_in', null, ['has_amnesty' => true]);
            $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->firstOrFail();

            $this->assertTrue((bool) $entry->is_late, 'el flag has_amnesty del cliente no debe otorgar amnistía');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_virtual_arrival_stamped_when_amnesty(): void
    {
        // spec:30,41: con amnistía, el check_in guarda la hora del "Ya llegué" como inicio
        // real de jornada (hora_llegada_virtual) para nómina/métricas.
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $this->insertWaiting($tenant->id, $user->id, '2026-07-10', '09:05:00'); // dentro de tolerancia

            $entry = $this->checkIn($user);

            $details = json_decode($entry->details, true) ?: [];
            $this->assertArrayHasKey('hora_llegada_virtual', $details);
            $this->assertSame('09:05:00', $details['hora_llegada_virtual']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_virtual_arrival_without_amnesty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(); // sin waiting → sin amnistía

            $entry = $this->checkIn($user);

            $details = json_decode($entry->details, true) ?: [];
            $this->assertArrayNotHasKey('hora_llegada_virtual', $details);
        } finally {
            Carbon::setTestNow();
        }
    }
}
