<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockPhotoConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    private function setSetting(string $key, $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => 1, 'key' => $key],
            ['value' => json_encode($value)]
        );
    }

    private function lastEntry(User $user)
    {
        return DB::table('time_entries')->where('user_id', $user->id)->orderByDesc('id')->first();
    }

    public function test_photo_present_records_full_verification(): void
    {
        $user = $this->makeEmployee();

        app(ClockService::class)->processPunch($user, 'check_in', null, [
            'photo_url' => '/uploads/clock-evidence/1/foto.jpg',
        ]);

        $e = $this->lastEntry($user);
        $this->assertEquals('GEOFENCE_PIN_PHOTO', $e->verification_method);
        $this->assertNull($e->photo_skipped_reason);
        $this->assertEquals(0, (int) $e->flagged_for_review);
        $this->assertEquals('/uploads/clock-evidence/1/foto.jpg', $e->photo_url);
    }

    public function test_photo_not_required_records_geofence_only(): void
    {
        $user = $this->makeEmployee();
        $this->setSetting('require_photo_on_clockin', false);

        app(ClockService::class)->processPunch($user, 'check_in', null, []);

        $e = $this->lastEntry($user);
        $this->assertEquals('GEOFENCE_ONLY', $e->verification_method);
        $this->assertEquals('not_required', $e->photo_skipped_reason);
        $this->assertEquals(0, (int) $e->flagged_for_review);
    }

    public function test_camera_failure_when_required_is_flagged_and_visible_to_supervisor(): void
    {
        $employee = $this->makeEmployee();
        // require_photo_on_clockin default = true

        app(ClockService::class)->processPunch($employee, 'check_in', null, [
            'photo_skipped_reason' => 'camera_unavailable',
        ]);

        $e = $this->lastEntry($employee);
        $this->assertEquals('GEOFENCE_PIN', $e->verification_method);
        $this->assertEquals('camera_unavailable', $e->photo_skipped_reason);
        $this->assertEquals(1, (int) $e->flagged_for_review);

        // Aparece en el monitor del supervisor.
        $supervisor = $this->makeEmployee();
        DB::table('users')->where('id', $supervisor->id)->update(['role' => 'supervisor']);
        $supervisor->refresh();

        $res = $this->actingAs($supervisor)->getJson('/api/v1/admin/clock/flagged-punches');
        $res->assertStatus(200);
        $res->assertJson(['success' => true, 'count' => 1]);
    }

    public function test_tardiness_snapshot_is_frozen_at_punch_time(): void
    {
        $user = $this->makeEmployee();
        DB::table('employees')->where('user_id', $user->id)->update(['shiftStart' => '09:00:00']);
        $this->setSetting('time_mode', 'simulated');
        $user->refresh();

        // Llega 30 min tarde (turno 09:00, tolerancia por defecto 10).
        app(ClockService::class)->processPunch($user, 'check_in', '09:30:00', []);

        $e = $this->lastEntry($user);
        $this->assertEquals(30, (int) $e->tardiness_minutes_at_time);
        $this->assertEquals(10, (int) $e->tolerance_mins_at_time);
        $this->assertNotNull($e->tolerance_version);
    }

    public function test_three_consecutive_camera_skips_notify_supervisor(): void
    {
        $user = $this->makeEmployee();

        // Dos fichajes previos con la cámara fallando (fechas anteriores).
        foreach (['2026-07-24', '2026-07-25'] as $d) {
            DB::table('time_entries')->insert([
                'user_id' => $user->id,
                'tenant_id' => 1,
                'date' => $d,
                'type' => 'check_in',
                'time' => '09:00:00',
                'is_late' => false,
                'late_minutes' => 0,
                'photo_skipped_reason' => 'camera_unavailable',
                'verification_method' => 'GEOFENCE_PIN',
                'flagged_for_review' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Tercer fichaje hoy, otra vez sin cámara → dispara el aviso de racha.
        app(ClockService::class)->processPunch($user, 'check_in', null, [
            'photo_skipped_reason' => 'camera_unavailable',
        ]);

        $this->assertDatabaseHas('saas_audit_logs', [
            'tenant_id' => 1,
            'user_id' => $user->id,
            'event_type' => 'clock_photo_skip_streak',
        ]);
    }
}
