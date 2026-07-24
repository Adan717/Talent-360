<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 45 (Reloj): cierra la familia de la deriva `users` vs `employees`.
 *
 * A) El contrato de auth debe traer los datos de jornada del EXPEDIENTE. La migración
 *    `2026_06_26_010708` eliminó de `users` las columnas de expediente (mealMinutes, shiftStart,
 *    shiftEnd, restDay…), pero el frontend las sigue leyendo de `currentUser`:
 *      - `useClockEngine.tsx:1263` y `RelojVisual.tsx:5658`:
 *        `currentUser?.mealMinutes || timeBankConfigs?.mealMinutes || 60` → como es SIEMPRE undefined,
 *        caía a un GLOBAL de 60 → toda reserva apartaba 4 bloques de 15 min aunque el empleado
 *        tuviera 30 o 90.
 *      - `MyAccountModal.tsx:445,457` mostraba un horario INVENTADO ('08:00 a 18:00', 'Domingo').
 *
 * B) `AuthController::updateProfile` escribía `users.name`/`avatar` **sin espejar a `employees`**,
 *    a diferencia de `uploadAvatar` (que sí lo hace). Como el Reloj arma su lista desde `employees`,
 *    un colaborador que se renombraba seguía saliendo con el nombre viejo indefinidamente.
 */
class PerfilYExpedienteSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Perfil',
            'subdomain' => 'empresa-perfil',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nombre Viejo',
            'email' => 'perfil@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Nombre Viejo',
            'is_active_employee' => true,
            'mealMinutes' => 30,          // NO el default de 60
            'shiftStart' => '14:00:00',
            'shiftEnd' => '22:00:00',
            'restDay' => 'Martes',
        ]);

        return [$tenant, $user->fresh(), $employee];
    }

    /**
     * A) /me debe traer los minutos de comida REALES del expediente (30), no el global de 60.
     */
    public function test_me_trae_los_minutos_de_comida_del_expediente(): void
    {
        [, $user] = $this->makeSetup();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame(
            30,
            (int) $response->json('user.mealMinutes'),
            'con undefined el front caía a 60 y apartaba 4 bloques de 15 min a todo el mundo'
        );
    }

    /**
     * A) …y también el turno y el día de descanso (MyAccountModal los muestra al colaborador).
     */
    public function test_me_trae_turno_y_dia_de_descanso_del_expediente(): void
    {
        [, $user] = $this->makeSetup();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame('14:00:00', $response->json('user.shiftStart'));
        $this->assertSame('22:00:00', $response->json('user.shiftEnd'));
        $this->assertSame('Martes', $response->json('user.restDay'), 'el modal mostraba "Domingo" hardcodeado');
    }

    /**
     * A) Sin expediente no se inventan datos: la cuenta administrativa no debe recibir jornada.
     */
    public function test_usuario_sin_expediente_no_recibe_datos_de_jornada(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Sin Exp',
            'subdomain' => 'empresa-sinexp',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin.perfil@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertNull($response->json('user.mealMinutes'));
        $this->assertNull($response->json('user.shiftStart'));
    }

    /**
     * B) Renombrarse desde "Mi Cuenta" debe espejarse al expediente: el Reloj lee `employees.name`.
     */
    public function test_update_profile_espeja_nombre_y_avatar_al_expediente(): void
    {
        [, $user, $employee] = $this->makeSetup();

        $response = $this->actingAs($user)->postJson('/api/v1/me/update-profile', [
            'name' => 'Nombre Nuevo',
            'avatar' => 'https://cdn.test/nuevo.png',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nombre Nuevo',
            'avatar' => 'https://cdn.test/nuevo.png',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'name' => 'Nombre Nuevo',
            'avatar' => 'https://cdn.test/nuevo.png',
        ]);
    }
}
