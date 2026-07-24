<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Geocerca validada en SERVIDOR (antes vivía solo en el frontend: cualquiera con devtools
 * saltaba el gate y fichaba desde su casa). Con `clockOpConfig.gpsValidationEnabled` y
 * `clockOpConfig.storeLocation` configurados, processPunch exige coordenadas en
 * details.gps para check_in/check_out y las valida por haversine contra el radio
 * (`gpsAlertRangeMeters`, default 100 m). Bypasses legítimos: validación apagada,
 * allowManualCheckIn, storeLocation sin configurar (backward compat) y
 * supervisor_override (calculado server-side del rol del emisor).
 */
class GeofencePunchTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_LAT = 19.4326;
    private const STORE_LNG = -99.1332;

    private function makeSetup(array $clockOpConfig = null): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Geo', 'subdomain' => 'geo-' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Empleado Geo',
            'email' => 'geo' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Empleado Geo',
            'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);

        if ($clockOpConfig !== null) {
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'clockOpConfig'],
                ['value' => json_encode($clockOpConfig), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return [$tenant, $user];
    }

    private function geoEnabledConfig(array $extra = []): array
    {
        return array_merge([
            'gpsValidationEnabled' => true,
            'gpsAlertRangeMeters' => 100,
            'storeLocation' => ['lat' => self::STORE_LAT, 'lng' => self::STORE_LNG],
            'allowManualCheckIn' => false,
        ], $extra);
    }

    public function test_punch_outside_radius_is_rejected(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig());

        // ~1.1 km al norte de la tienda.
        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
            'details' => ['gps' => ['latitude' => 19.4426, 'longitude' => self::STORE_LNG]],
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    public function test_punch_inside_radius_is_accepted(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig());

        // ~30 m de la tienda (dentro de los 100 m).
        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
            'details' => ['gps' => ['latitude' => self::STORE_LAT + 0.00027, 'longitude' => self::STORE_LNG]],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    public function test_punch_without_gps_is_rejected_when_geofence_active(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig());

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    public function test_disabled_validation_skips_geofence(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig(['gpsValidationEnabled' => false]));

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    public function test_allow_manual_check_in_skips_geofence(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig(['allowManualCheckIn' => true]));

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
        ]);

        $response->assertStatus(200);
    }

    public function test_unconfigured_store_location_skips_geofence(): void
    {
        // gpsValidationEnabled=true pero SIN storeLocation: no hay contra qué validar
        // (compat con tenants existentes que nunca configuraron su ubicación).
        [, $user] = $this->makeSetup(['gpsValidationEnabled' => true, 'gpsAlertRangeMeters' => 100]);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
        ]);

        $response->assertStatus(200);
    }

    public function test_supervisor_override_bypasses_geofence(): void
    {
        [$tenant, $user] = $this->makeSetup($this->geoEnabledConfig());
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin Geo',
            'email' => 'admgeo' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        // El admin ficha por el empleado sin GPS: supervisor_override (server-side) manda.
        $response = $this->actingAs($admin)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    public function test_check_out_outside_radius_is_rejected(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig());

        // La geocerca aplica también a check_out (no solo check_in): salir del perímetro y
        // fichar salida a ~1.1 km debe rechazarse igual.
        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_out',
            'details' => ['gps' => ['latitude' => 19.4426, 'longitude' => self::STORE_LNG]],
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
    }

    public function test_negative_radius_does_not_lock_out_person_at_store(): void
    {
        // Radio 0 (mal capturado) se clampa a >=1 m: quien está EN la tienda (misma coord)
        // no debe quedar bloqueado.
        [, $user] = $this->makeSetup($this->geoEnabledConfig(['gpsAlertRangeMeters' => 0]));

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in',
            'details' => ['gps' => ['latitude' => self::STORE_LAT, 'longitude' => self::STORE_LNG]],
        ]);

        $response->assertStatus(200);
    }

    public function test_non_attendance_types_not_geofenced(): void
    {
        [, $user] = $this->makeSetup($this->geoEnabledConfig());

        // meal_start no exige geocerca (movimiento interno, no entrada/salida del local).
        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'meal_start',
        ]);

        $response->assertStatus(200);
    }
}
