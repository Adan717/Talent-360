<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Repara los cierres automáticos de CERO minutos que dejó la regla vieja del barrido.
 */
class RepararCierresSinteticosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Reparar QA', 'subdomain' => 'repararqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador',
            'email' => 'rep@repararqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
    }

    private function punch(string $type, string $time, array $details = null): int
    {
        return DB::table('time_entries')->insertGetId([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'date' => '2026-08-21', 'type' => $type, 'time' => $time,
            'is_late' => false, 'late_minutes' => 0,
            'details' => $details ? json_encode($details) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_simulacro_no_escribe_nada(): void
    {
        $this->punch('check_in', '20:33:05');
        $id = $this->punch('check_out', '20:33:05', ['auto_closed' => true, 'reason' => 'orphan_shift']);

        $this->artisan('shifts:reparar-cierres-sinteticos')->assertExitCode(0);

        $this->assertDatabaseHas('time_entries', ['id' => $id]);
    }

    public function test_quita_el_cierre_de_cero_minutos_y_deja_el_aviso(): void
    {
        $this->punch('check_in', '20:33:05');
        $id = $this->punch('check_out', '20:33:05', ['auto_closed' => true, 'reason' => 'orphan_shift']);

        $this->artisan('shifts:reparar-cierres-sinteticos', ['--aplicar' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('time_entries', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'type' => 'orphan_shift',
        ]);
    }

    public function test_no_toca_un_cierre_automatico_correcto(): void
    {
        $this->punch('check_in', '09:00:00');
        $id = $this->punch('check_out', '18:00:00', ['auto_closed' => true, 'reason' => 'orphan_shift']);

        $this->artisan('shifts:reparar-cierres-sinteticos', ['--aplicar' => true])->assertExitCode(0);

        $this->assertDatabaseHas('time_entries', ['id' => $id]);
    }

    public function test_no_toca_una_salida_real(): void
    {
        $this->punch('check_in', '20:33:05');
        $id = $this->punch('check_out', '20:33:05'); // sin marca auto_closed: la puso una persona

        $this->artisan('shifts:reparar-cierres-sinteticos', ['--aplicar' => true])->assertExitCode(0);

        $this->assertDatabaseHas('time_entries', ['id' => $id]);
    }
}
