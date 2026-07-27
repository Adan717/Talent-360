<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PayrollWeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Tests\TestCase;

class PayrollWeekConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->refresh();
    }

    public function test_decorarte_defaults_to_sunday_start_and_saturday_pay(): void
    {
        // El seed de migración fija DecorArte a domingo(0)/sábado(6).
        $service = app(PayrollWeekService::class);
        $this->assertSame(0, $service->weekStartDay(1)); // domingo
        $this->assertSame(6, $service->payDay(1));        // sábado
    }

    public function test_week_range_respects_sunday_start(): void
    {
        $service = app(PayrollWeekService::class);

        // Miércoles 2026-07-22. Con semana que inicia domingo, la semana es
        // domingo 2026-07-19 → sábado 2026-07-25.
        [$start, $end] = $service->weekRangeFor(1, Carbon::parse('2026-07-22'));

        $this->assertSame('2026-07-19', $start->toDateString());
        $this->assertSame('2026-07-25', $end->toDateString());
        $this->assertSame(0, (int) $start->dayOfWeek); // domingo
        $this->assertSame(6, (int) $end->dayOfWeek);    // sábado
    }

    public function test_a_tenant_with_monday_start_gets_a_monday_sunday_week(): void
    {
        $service = app(PayrollWeekService::class);
        // Otro tenant sin config → default global lunes(1).
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otra', 'subdomain' => 't2', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$start, $end] = $service->weekRangeFor(2, Carbon::parse('2026-07-22')); // miércoles

        $this->assertSame('2026-07-20', $start->toDateString()); // lunes
        $this->assertSame('2026-07-26', $end->toDateString());    // domingo
    }

    public function test_get_and_save_payroll_settings(): void
    {
        $admin = $this->makeAdmin();

        $get = $this->actingAs($admin)->getJson('/api/v1/company/payroll-settings');
        $get->assertStatus(200);
        $get->assertJson(['week_start_day' => 0, 'pay_day' => 6]); // seed de DecorArte

        $save = $this->actingAs($admin)->putJson('/api/v1/company/payroll-settings', [
            'week_start_day' => 4, // jueves
            'pay_day' => 5,        // viernes
            'calc_time' => '22:30',
        ]);
        $save->assertStatus(200);

        $after = $this->actingAs($admin)->getJson('/api/v1/company/payroll-settings');
        $after->assertJson(['week_start_day' => 4, 'pay_day' => 5, 'calc_time' => '22:30']);
    }

    public function test_save_rejects_invalid_day(): void
    {
        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->putJson('/api/v1/company/payroll-settings', [
            'week_start_day' => 9,
        ]);
        $response->assertStatus(422);
    }
}
