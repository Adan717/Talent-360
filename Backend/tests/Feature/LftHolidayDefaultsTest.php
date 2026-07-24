<?php

namespace Tests\Feature;

use App\Http\Controllers\LftSettingController;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los festivos oficiales por defecto se precargan para el AÑO ACTUAL, no para un año
 * hardcodeado (2026). Los festivos "recorrido" (a lunes) cambian de fecha cada año, así
 * que deben computarse, no copiarse. Bomba de tiempo: un tenant que precargaba en 2027
 * obtenía fechas de 2026.
 */
class LftHolidayDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_seeds_holidays_for_the_current_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-06-01 12:00:00'));
        try {
            $tenant = Tenant::create([
                'name' => 'Empresa Festivos',
                'subdomain' => 'holidays',
                'plan' => 'enterprise',
                'is_active' => true,
            ]);
            $admin = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Admin',
                'email' => 'admin@holidays.local',
                'password' => bcrypt('password'),
                'role' => 'admin',
            ]);

            $this->actingAs($admin)->getJson('/api/v1/admin/lft-holidays')->assertStatus(200);

            // Fijos del año actual (2027), no 2026.
            $this->assertDatabaseHas('lft_holidays', [
                'tenant_id' => $tenant->id, 'name' => 'Año Nuevo', 'date' => '2027-01-01',
            ]);
            $this->assertDatabaseHas('lft_holidays', [
                'tenant_id' => $tenant->id, 'name' => 'Navidad', 'date' => '2027-12-25',
            ]);
            // Recorrido: 3er lunes de noviembre 2027 = 2027-11-15.
            $this->assertDatabaseHas('lft_holidays', [
                'tenant_id' => $tenant->id, 'date' => '2027-11-15',
            ]);
            $this->assertDatabaseMissing('lft_holidays', [
                'tenant_id' => $tenant->id, 'date' => '2026-01-01',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_default_holidays_reproduce_2026_recorrido_dates(): void
    {
        // Regresión: la computación reproduce las fechas 2026 verificadas a mano.
        $h = collect(LftSettingController::defaultHolidaysForYear(2026))->keyBy('name');
        $this->assertSame('2026-02-02', $h['Aniversario de la Constitución (recorrido)']['date']);
        $this->assertSame('2026-03-16', $h['Natalicio de Benito Juárez (recorrido)']['date']);
        $this->assertSame('2026-11-16', $h['Día de la Revolución (recorrido)']['date']);
    }

    public function test_default_holidays_advance_recorrido_for_2027(): void
    {
        $h = collect(LftSettingController::defaultHolidaysForYear(2027))->keyBy('name');
        $this->assertSame('2027-02-01', $h['Aniversario de la Constitución (recorrido)']['date']); // 1er lunes feb
        $this->assertSame('2027-03-15', $h['Natalicio de Benito Juárez (recorrido)']['date']); // 3er lunes mar
        $this->assertSame('2027-11-15', $h['Día de la Revolución (recorrido)']['date']); // 3er lunes nov
    }
}
