<?php

namespace Tests\Feature;

use App\Events\TimeEntryRecorded;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TimeEntryRecordedEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_event_fires_with_correct_payload_on_successful_punch(): void
    {
        Event::fake([TimeEntryRecorded::class]);

        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        Event::assertDispatched(TimeEntryRecorded::class, function (TimeEntryRecorded $event) use ($user) {
            return $event->tenantId === 1
                && $event->userId === $user->id
                && $event->type === 'check_in';
        });
    }

    public function test_event_does_not_fire_on_rejected_punch(): void
    {
        Event::fake([TimeEntryRecorded::class]);

        $user = $this->makeUser();

        // check_out sin check_in previo — la validación de secuencia (§15) lo rechaza.
        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '18:00:00',
        ])->assertStatus(400);

        Event::assertNotDispatched(TimeEntryRecorded::class);
    }

    public function test_event_fires_once_per_valid_item_in_punch_batch(): void
    {
        Event::fake([TimeEntryRecorded::class]);

        $user = $this->makeUser();
        $secret = $this->actingAs($user)->getJson('/api/v1/clock/offline-secret')->json('secret');

        // ENMIENDA resync 2026-07-28: fechas RELATIVAS — el timestamp fijo original
        // (2026-07-21) cayó fuera de la ventana MAX_AGE_DAYS del batch y el ponche se
        // rechazaba por "demasiado antiguo" (mismo mal que ClockPunchBatchTest, 0dd7e24).
        $momento = now()->subHours(2);
        $time = $momento->format('H:i:s');
        $timestamp = $momento->toIso8601String();
        $stamp = hash_hmac('sha256', "{$user->id}|check_in|{$time}|{$timestamp}", $secret);

        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [[
                'user_id' => $user->id,
                'type' => 'check_in',
                'time' => $time,
                'client_timestamp' => $timestamp,
                'offline_stamp' => $stamp,
            ]],
        ])->assertStatus(200);

        Event::assertDispatchedTimes(TimeEntryRecorded::class, 1);
    }
}
