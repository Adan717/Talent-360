<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R87 (Fase 3 / T3.2 del plan v2): Alarma de traslado (pre-shift alarm).
 *
 * Spec §4: "07:45 — Alarma de traslado en perfil (push local 'Es hora de salir…')". El colaborador
 * elige cuántos minutos ANTES de su turno quiere el aviso (15/30/45/60, o desactivada). El aviso es
 * una notificación LOCAL del dispositivo (no FCM); el backend sólo persiste la preferencia.
 *
 * La columna vive en `employees` (NO en `users`): la jornada (shiftStart, etc.) vive ahí y el reloj
 * arma su estado desde el expediente (getState/toAuthPayload) — una columna en `users` se perdería,
 * como enseñó la saga R41/R44/R45.
 */
class PreShiftAlarmTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Alarma', 'subdomain' => 'al' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'al' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);
        return [$tenant, $user];
    }

    public function test_el_empleado_fija_su_alarma(): void
    {
        [$tenant, $user] = $this->makeUser();

        $res = $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 30]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('employees', ['user_id' => $user->id, 'pre_shift_alarm_minutes' => 30]);
        // La respuesta trae el payload de auth con la preferencia (para refrescar currentUser).
        $this->assertSame(30, $res->json('user.pre_shift_alarm_minutes'));
    }

    public function test_desactivar_con_cero(): void
    {
        [$tenant, $user] = $this->makeUser();
        DB::table('employees')->where('user_id', $user->id)->update(['pre_shift_alarm_minutes' => 45]);

        $res = $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 0]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('employees', ['user_id' => $user->id, 'pre_shift_alarm_minutes' => 0]);
        $this->assertSame(0, $res->json('user.pre_shift_alarm_minutes'));
    }

    public function test_solo_valores_permitidos(): void
    {
        [, $user] = $this->makeUser();
        // 20 no está en {0,15,30,45,60}.
        $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 20])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 'x'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', [])->assertStatus(422);
    }

    public function test_sin_expediente_no_guarda(): void
    {
        $tenant = Tenant::create(['name' => 'E', 'subdomain' => 'e' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Sin Exp', 'email' => 'se' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 30])->assertStatus(422);
    }

    public function test_la_preferencia_llega_en_me(): void
    {
        [, $user] = $this->makeUser();
        DB::table('employees')->where('user_id', $user->id)->update(['pre_shift_alarm_minutes' => 15]);

        $res = $this->actingAs($user)->getJson('/api/v1/me');

        $res->assertStatus(200);
        // /me anida el payload de auth bajo `user` (junto a shiftStart, mealMinutes, etc.).
        $this->assertSame(15, $res->json('user.pre_shift_alarm_minutes'));
    }

    public function test_no_cruza_a_otro_empleado(): void
    {
        [$tenant, $user] = $this->makeUser();
        $jr = JobRole::where('tenant_id', $tenant->id)->first();
        $otroUser = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Otro', 'email' => 'o' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $otroUser->id, 'name' => 'Otro',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/pre-shift-alarm', ['minutes' => 60]);

        $this->assertDatabaseHas('employees', ['user_id' => $user->id, 'pre_shift_alarm_minutes' => 60]);
        // El otro empleado NO se tocó (default null).
        $this->assertNull(DB::table('employees')->where('user_id', $otroUser->id)->value('pre_shift_alarm_minutes'));
    }
}
