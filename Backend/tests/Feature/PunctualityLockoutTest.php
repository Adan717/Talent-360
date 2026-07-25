<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R102 (cierre del spec 3/3): estado #1 del dial ("🔒 Fichaje Bloqueado, 3 retardos →
 * completa curso") con datos REALES, no localStorage.
 *
 * El teatro que se cierra: el contador `user_retardos_<id>` vivía SÓLO en localStorage — por
 * dispositivo, borrable con limpiar el navegador, e invisible para el servidor. La fuente real ya
 * existía: cada entrada tardía autorizada por QR/PIN postea un audit_log `late_entry_unlocked`
 * (useClockEngine ~3587) — paridad semántica EXACTA con lo que el contador contaba.
 *
 * Diseño:
 *  - `employees.punctuality_reset_at` (nullable): marcador del último curso de Puntualidad aprobado.
 *  - `toAuthPayload` expone `punctuality_lockout_count` = COUNT(late_entry_unlocked DESPUÉS del
 *    marcador) → llega al FE en login (patrón R87 de pre_shift_alarm_minutes).
 *  - `POST /me/punctuality-course-reset`: la Academia lo llama al aprobar el curso (funciona
 *    también para el curso SINTÉTICO id 999 — no toca FKs de cursos).
 *  - Guard anti-sabotaje en `syncAuditLog`: un empleado NO puede insertar `late_entry_unlocked`
 *    para OTRO usuario (bloquearía el dial del colega); mandos sí (simulador tenant 1, R87).
 */
class PunctualityLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Punt', 'subdomain' => 'punt' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador Punt',
            'email' => 'punt' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador Punt',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        return [$tenant, $user, $employee];
    }

    private function lateUnlock(Tenant $tenant, User $user, ?string $createdAt = null): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'date' => now()->format('Y-m-d'), 'type' => 'late_entry_unlocked',
            'timestamp_str' => '09:30', 'reason' => 'QR supervisor',
            'punishment_amount' => 0,
            'created_at' => $createdAt ?? now(), 'updated_at' => $createdAt ?? now(),
        ]);
    }

    public function test_el_payload_de_login_trae_el_conteo_real(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $this->lateUnlock($tenant, $user);
        $this->lateUnlock($tenant, $user);
        $this->lateUnlock($tenant, $user);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('user.punctuality_lockout_count'));
    }

    public function test_sin_retardos_el_conteo_es_cero(): void
    {
        [, $user] = $this->makeSetup();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $this->assertSame(0, $response->json('user.punctuality_lockout_count'));
    }

    public function test_el_reset_del_curso_pone_el_conteo_en_cero(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->lateUnlock($tenant, $user);
        $this->lateUnlock($tenant, $user);
        $this->lateUnlock($tenant, $user);

        $this->actingAs($user)->postJson('/api/v1/me/punctuality-course-reset')
            ->assertStatus(200);

        $this->assertNotNull($employee->fresh()->punctuality_reset_at, 'el marcador debe persistir');
        $response = $this->actingAs($user)->getJson('/api/v1/me');
        $this->assertSame(0, $response->json('user.punctuality_lockout_count'));
    }

    public function test_retardos_posteriores_al_reset_si_cuentan(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->lateUnlock($tenant, $user, now()->subDays(3));
        $employee->punctuality_reset_at = now()->subDay();
        $employee->save();
        $this->lateUnlock($tenant, $user); // después del reset

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $this->assertSame(1, $response->json('user.punctuality_lockout_count'));
    }

    public function test_el_conteo_no_mezcla_usuarios(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $otro = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Otro',
            'email' => 'otro' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $otro->id, 'name' => 'Otro',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        $this->lateUnlock($tenant, $otro);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $this->assertSame(0, $response->json('user.punctuality_lockout_count'));
    }

    public function test_sin_expediente_no_hay_conteo(): void
    {
        [$tenant] = $this->makeSetup();
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin Sin Expediente',
            'email' => 'adm' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertNull($response->json('user.punctuality_lockout_count'));
    }

    // ---- Guard anti-sabotaje en syncAuditLog ------------------------------------------

    public function test_empleado_no_puede_insertar_late_unlock_de_otro(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $victima = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Victima',
            'email' => 'vic' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        $this->actingAs($user)->postJson('/api/v1/sync/audit_log', [
            'user_id' => $victima->id,
            'type' => 'late_entry_unlocked',
            'timestamp_str' => '09:30',
            'reason' => 'forjado',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $victima->id, 'type' => 'late_entry_unlocked',
        ]);
    }

    public function test_mando_si_puede_insertar_late_unlock_de_otro(): void
    {
        // El simulador del tenant 1 postea con el token del ADMIN para el empleado simulado (R87).
        [$tenant, $user] = $this->makeSetup();
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin',
            'email' => 'adm' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/sync/audit_log', [
            'user_id' => $user->id,
            'type' => 'late_entry_unlocked',
            'timestamp_str' => '09:30',
            'reason' => 'QR supervisor (simulador)',
        ])->assertStatus(200);
    }

    public function test_empleado_si_puede_postear_su_propio_late_unlock(): void
    {
        [, $user] = $this->makeSetup();

        $this->actingAs($user)->postJson('/api/v1/sync/audit_log', [
            'user_id' => $user->id,
            'type' => 'late_entry_unlocked',
            'timestamp_str' => '09:30',
            'reason' => 'QR supervisor',
        ])->assertStatus(200);
    }
}
