<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ronda 59 (Reloj): la PRESENCIA de un día laboral no puede probarse con un ponche que NO es de
 * asistencia. `calculatePayrollForEmployee` marcaba el día como presente si existía CUALQUIER entrada
 * (`isset($entriesByDate[$dateStr])`), incluidos `waiting` ("Ya llegué"), `temp_exit_*`, `break_*` y
 * `meal_start/end`.
 *
 * R36 lo DESTAPÓ: antes esos tipos no podían persistir (un CHECK huérfano de Postgres los bloqueaba),
 * así que un día sin `check_in` nunca tenía entradas → falta. Desde R36 persisten, y ahora un
 * empleado que toca "Ya llegué" y se marcha SIN fichar entrada queda registrado como PRESENTE y cobra
 * el día. Es un bug de dinero / vector de fraude, misma clase que R38/R48.
 *
 * Regla correcta: un día laboral cuenta como asistido sólo si hay un ponche de ASISTENCIA real
 * (check_in / check_out). Los auxiliares y señales (waiting, temp_exit, break, meal) sólo ocurren en
 * el contexto de una jornada ya iniciada; por sí solos no prueban asistencia.
 */
class PayrollWaitingPresenceTest extends TestCase
{
    use RefreshDatabase;

    /** Semana 2026-06-01 (Lun) .. 2026-06-07 (Dom); descanso Domingo. */
    private function setupEmp(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Waiting', 'subdomain' => 'wait-' . uniqid(), 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'w' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'lates_per_absence' => 3, 'deduct_absence_day' => true,
            'absences_for_warning' => 3, 'absences_for_suspension' => 4, 'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15, 'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct', 'paid_rest_day' => true,
        ]);
        return [$tenant, $user, $employee];
    }

    private function entry(int $userId, int $tenantId, string $date, string $type, string $time): void
    {
        TimeEntry::create([
            'user_id' => $userId, 'tenant_id' => $tenantId, 'date' => $date,
            'type' => $type, 'time' => $time, 'is_late' => false, 'late_minutes' => 0,
        ]);
    }

    /** Ficha entrada normal todos los días laborales. */
    private function checkInAll(int $userId, int $tenantId, array $dates): void
    {
        foreach ($dates as $d) {
            $this->entry($userId, $tenantId, $d, 'check_in', '08:55:00');
        }
    }

    /**
     * EL BUG: un día laboral con SÓLO un `waiting` (tocó "Ya llegué" y se fue) debe contar como
     * FALTA, no como presente.
     */
    public function test_un_dia_con_solo_waiting_es_falta(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        // Ficha entrada de verdad lun-vie; el sábado SÓLO deja un waiting (no fichó).
        $this->checkInAll($user->id, $tenant->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05']);
        $this->entry($user->id, $tenant->id, '2026-06-06', 'waiting', '09:05:00'); // sábado, sólo waiting

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertSame(1, (int) $payroll['incidents']['physical_absences'], 'el sábado con sólo un waiting debe ser falta');
    }

    /** Mismo patrón con `temp_exit_start` y `break_start`: no prueban asistencia por sí solos. */
    public function test_un_dia_con_solo_temp_exit_o_break_es_falta(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        $this->checkInAll($user->id, $tenant->id, ['2026-06-01', '2026-06-02', '2026-06-03']);
        $this->entry($user->id, $tenant->id, '2026-06-04', 'temp_exit_start', '10:00:00'); // jueves, sin check_in
        $this->entry($user->id, $tenant->id, '2026-06-05', 'break_start', '11:00:00');     // viernes, sin check_in
        $this->entry($user->id, $tenant->id, '2026-06-06', 'meal_start', '14:00:00');      // sábado, sin check_in

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertSame(3, (int) $payroll['incidents']['physical_absences'], 'jue/vie/sáb sin check_in son 3 faltas');
    }

    /** REGRESIÓN: un día con `check_in` (aunque además tenga waiting/meal) SÍ es presente. */
    public function test_un_dia_con_check_in_es_presente(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        $this->checkInAll($user->id, $tenant->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06']);
        // Además, ruido auxiliar en un día: waiting + meal. No debe cambiar la presencia.
        $this->entry($user->id, $tenant->id, '2026-06-06', 'waiting', '08:50:00');
        $this->entry($user->id, $tenant->id, '2026-06-06', 'meal_start', '14:00:00');
        $this->entry($user->id, $tenant->id, '2026-06-06', 'meal_end', '14:40:00');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertSame(0, (int) $payroll['incidents']['physical_absences'], 'con check_in todos los días, 0 faltas');
    }

    /** Un `check_out` también prueba asistencia (jornada iniciada y cerrada). */
    public function test_un_dia_con_check_out_es_presente(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        $this->checkInAll($user->id, $tenant->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05']);
        // Sábado: sólo un check_out (dato anómalo, pero es asistencia). No debe ser falta.
        $this->entry($user->id, $tenant->id, '2026-06-06', 'check_out', '18:00:00');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertSame(0, (int) $payroll['incidents']['physical_absences']);
    }

    /**
     * El grid diario expone `attended` (asistencia real) separado de `has_entries` (hay ponches que
     * mostrar). Un día con sólo `waiting` tiene entradas para mostrar pero NO es asistencia — si el FE
     * pintara "presente" por `has_entries` enmascararía el fraude. (Hallazgo del code-review.)
     */
    public function test_el_grid_distingue_attended_de_has_entries(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        $this->entry($user->id, $tenant->id, '2026-06-02', 'waiting', '09:05:00'); // martes: sólo waiting

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-02', '2026-06-02');
        $day = $payroll['days_details'][0];

        $this->assertTrue($day['has_entries'], 'hay un waiting que mostrar');
        $this->assertFalse($day['attended'], 'pero NO asistió: sólo un waiting');
    }

    /**
     * El festivo trabajado paga bono doble; un festivo con SÓLO `waiting` (no fichó) NO debe pagarlo.
     * El fix de presencia cierra también este segundo vector.
     */
    public function test_festivo_con_solo_waiting_no_paga_bono(): void
    {
        [$tenant, $user, $employee] = $this->setupEmp();
        // 2026-06-04 (jueves) marcado festivo que bloquea la app sólo si no se labora.
        \Illuminate\Support\Facades\DB::table('lft_holidays')->insert([
            'tenant_id' => $tenant->id, 'date' => '2026-06-04', 'name' => 'Día de Prueba',
            'block_app' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry($user->id, $tenant->id, '2026-06-04', 'waiting', '09:05:00'); // sólo waiting en el festivo

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-04', '2026-06-04');

        $this->assertSame(0, (int) $payroll['incidents']['holidays_worked'], 'un festivo con sólo waiting no cuenta como trabajado');
    }
}
