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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Salida Doble Llave (spec:53-55): con `require_checkout_approval` activo, la salida del
 * empleado queda en `pending_approval` hasta que un supervisor la autoriza. El flag es
 * por-tenant y default false (comportamiento previo intacto).
 */
class CheckoutApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(bool $requireApproval): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Salida',
            'subdomain' => 'salida' . uniqid(),
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador',
            'email' => 'colab' . uniqid() . '@t.local',
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
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        LftSetting::create([
            'tenant_id' => $tenant->id,
            'require_checkout_approval' => $requireApproval,
        ]);
        return [$tenant, $user];
    }

    private function makeUser(int $tenantId, string $role): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => 'U ' . $role,
            'email' => $role . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }

    /**
     * ENMIENDA merge F3: la máquina de estados de secuencia (§15 reconciliado) exige un turno
     * ABIERTO para el check_out — se abre el turno con un check_in directo en BD (no cambia lo
     * que este test afirma: el estado de aprobación de la salida).
     */
    private function insertCheckIn(User $user, string $time = '09:00:00'): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'type' => 'check_in',
            'time' => $time,
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function checkOutId(User $user): int
    {
        $this->insertCheckIn($user);
        app(ClockService::class)->processPunch($user, 'check_out');
        return (int) TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->value('id');
    }

    public function test_checkout_is_pending_when_approval_required(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            $this->checkOutId($user);

            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out', 'check_out_status' => 'pending_approval',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_checkout_is_final_when_approval_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(false);
            $this->checkOutId($user);

            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out', 'check_out_status' => 'final',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_supervisor_authorizes_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            $id = $this->checkOutId($user);
            $supervisor = $this->makeUser($tenant->id, 'supervisor');

            $response = $this->actingAs($supervisor)->postJson("/api/v1/clock/check-out/{$id}/authorize");

            $response->assertStatus(200);
            $this->assertDatabaseHas('time_entries', ['id' => $id, 'check_out_status' => 'approved']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_supervisor_cannot_authorize(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            $id = $this->checkOutId($user);

            // El propio empleado (rol empleado) intenta autorizar → 403 por RoleMiddleware.
            $response = $this->actingAs($user)->postJson("/api/v1/clock/check-out/{$id}/authorize");

            $response->assertStatus(403);
            $this->assertDatabaseHas('time_entries', ['id' => $id, 'check_out_status' => 'pending_approval']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cross_tenant_authorize_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenantA, $userA] = $this->makeSetup(true);
            $id = $this->checkOutId($userA);

            $tenantB = Tenant::create([
                'name' => 'Otro', 'subdomain' => 'otro' . uniqid(), 'plan' => 'enterprise', 'is_active' => true,
            ]);
            $supB = $this->makeUser($tenantB->id, 'supervisor');

            $response = $this->actingAs($supB)->postJson("/api/v1/clock/check-out/{$id}/authorize");

            $response->assertStatus(403);
            $this->assertDatabaseHas('time_entries', ['id' => $id, 'check_out_status' => 'pending_approval']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_authorize_non_pending_is_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(false); // el check_out será 'final'
            $id = $this->checkOutId($user);
            $supervisor = $this->makeUser($tenant->id, 'supervisor');

            $response = $this->actingAs($supervisor)->postJson("/api/v1/clock/check-out/{$id}/authorize");

            $response->assertStatus(422);
            $this->assertDatabaseHas('time_entries', ['id' => $id, 'check_out_status' => 'final']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_authorize_legacy_null_checkout_is_422(): void
    {
        // Un check_out legacy / de cierre forzado (CRON huérfanos, Kill-Switch) tiene
        // check_out_status NULL → NO es 'pending_approval' → no autorizable (422).
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            DB::table('time_entries')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
                'type' => 'check_out', 'time' => '18:00:00', 'is_late' => false, 'late_minutes' => 0,
                'check_out_status' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = (int) TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->value('id');
            $supervisor = $this->makeUser($tenant->id, 'supervisor');

            $this->actingAs($supervisor)->postJson("/api/v1/clock/check-out/{$id}/authorize")
                ->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_checkout_entry_has_null_status(): void
    {
        // Un ponche que NO es check_out (p.ej. meal_end) no lleva estado de aprobación.
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            // ENMIENDA merge F3: meal_end exige su meal_start abierto (secuencia §15).
            $this->insertCheckIn($user);
            DB::table('time_entries')->insert([
                'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
                'date' => Carbon::now()->format('Y-m-d'), 'type' => 'meal_start', 'time' => '13:00:00',
                'is_late' => false, 'late_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(ClockService::class)->processPunch($user, 'meal_end');

            $entry = TimeEntry::where('user_id', $user->id)->where('type', 'meal_end')->first();
            $this->assertNotNull($entry);
            $this->assertNull($entry->check_out_status);
        } finally {
            Carbon::setTestNow();
        }
    }
}
