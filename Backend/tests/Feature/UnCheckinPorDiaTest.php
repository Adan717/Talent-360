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
 * Reloj R63 (cazado en la prueba a ESCALA): guard de ESTADO contra ponches de asistencia duplicados.
 *
 * BUG DE DINERO: `processPunch` no impedía un check_in duplicado. Un segundo check_in con el turno
 * ABIERTO (doble toque en el kiosko, reintento de la cola offline, retry de red) creaba otra fila, y
 * la nómina (`calculatePayrollForEmployee:445`) SUMA `late_minutes` por entrada → con 3 check_in de
 * 180 min el retardo sería 540 en vez de 180.
 *
 * NO es "uno por día": el turno PARTIDO (check_in→check_out→check_in el mismo día) es legítimo. La
 * regla es de máquina de estados: un check_in sólo vale desde un turno CERRADO; un check_out sólo con
 * un turno abierto.
 */
class UnCheckinPorDiaTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa U', 'subdomain' => 'u' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'u' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        LftSetting::create(['tenant_id' => $tenant->id, 'max_late_block_minutes' => 0]);
        return [$tenant, $user];
    }

    private function countType(int $userId, string $type): int
    {
        return DB::table('time_entries')->where('user_id', $userId)->where('type', $type)
            ->where('date', '2026-07-10')->count();
    }

    public function test_check_in_duplicado_con_turno_abierto_es_idempotente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00')); // 180 min tarde
        try {
            [, $user] = $this->makeUser();
            $svc = app(ClockService::class);

            $svc->processPunch($user, 'check_in');
            $r2 = $svc->processPunch($user, 'check_in'); // duplicado, turno abierto
            $r3 = $svc->processPunch($user, 'check_in'); // otro

            $this->assertSame(1, $this->countType($user->id, 'check_in'), 'sólo UN check_in con el turno abierto');
            $this->assertTrue($r2['duplicate'] ?? false);
            $this->assertTrue($r3['duplicate'] ?? false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_retardo_no_se_infla_por_check_ins_duplicados(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00')); // 180 min tarde
        try {
            [, $user] = $this->makeUser();
            $svc = app(ClockService::class);

            $svc->processPunch($user, 'check_in');
            $svc->processPunch($user, 'check_in');
            $svc->processPunch($user, 'check_in');

            $totalLate = (int) DB::table('time_entries')->where('user_id', $user->id)
                ->where('date', '2026-07-10')->sum('late_minutes');
            $this->assertLessThan(300, $totalLate, 'el retardo no debe sumarse por duplicados');
            $this->assertGreaterThan(100, $totalLate, 'pero el retardo real (1 entrada) sí cuenta');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El TURNO PARTIDO es legítimo: check_in → check_out → check_in el mismo día se permite. */
    public function test_el_turno_partido_se_permite(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $user] = $this->makeUser();
            $svc = app(ClockService::class);

            $svc->processPunch($user, 'check_in');   // sesión 1
            $svc->processPunch($user, 'check_out');
            $svc->processPunch($user, 'check_in');   // sesión 2 (re-abre) — DEBE permitirse
            $svc->processPunch($user, 'check_out');

            $this->assertSame(2, $this->countType($user->id, 'check_in'), 'dos entradas legítimas en el turno partido');
            $this->assertSame(2, $this->countType($user->id, 'check_out'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * El guard es SÓLO para check_in (el único que infla nómina). Un check_out NO se bloquea, para
     * no romper los flujos que registran check_out sin check_in previo (cierre forzado, aprobación,
     * geocerca de salida). El foco es el bug de dinero del check_in.
     */
    public function test_el_check_out_no_queda_bloqueado_por_el_guard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeUser();
            $svc = app(ClockService::class);

            // check_out directo (sin check_in previo) debe registrarse, no ser idempotente.
            $r = $svc->processPunch($user, 'check_out');

            $this->assertSame(1, $this->countType($user->id, 'check_out'));
            $this->assertFalse($r['duplicate'] ?? false);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Los tipos que SÍ se repiten (comida, descanso, waiting) no se ven afectados. */
    public function test_los_tipos_repetibles_siguen_permitidos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00'));
        try {
            [, $user] = $this->makeUser();
            $svc = app(ClockService::class);

            $svc->processPunch($user, 'check_in');
            $svc->processPunch($user, 'meal_start');
            $svc->processPunch($user, 'meal_end');
            $svc->processPunch($user, 'waiting');
            $svc->processPunch($user, 'waiting');

            $this->assertSame(1, $this->countType($user->id, 'meal_start'));
            $this->assertSame(2, $this->countType($user->id, 'waiting'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
