<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 44 (Reloj): `avatar` y `has_completed_induction` deben poder escribirse en `users`.
 *
 * Bug cazado en la auditoría R42 y confirmado en vivo con tinker: el atributo `#[Fillable]` del
 * modelo User NO incluía esas dos columnas, así que TODA escritura por Eloquent se descartaba
 * **en silencio** (sin excepción). Consecuencias:
 *  - `AcademyController:122,151` hace `auth()->user()->update(['has_completed_induction' => true])`
 *    al terminar la inducción → nunca persistía → la columna quedaba `false` PARA SIEMPRE y el
 *    Reloj trataba a todo colaborador como "inducción incompleta" (gates en RelojVisual 447/1270/3930).
 *  - Los 5 sitios que sincronizan `users.avatar` (EmployeeController, OnboardingController) no hacían
 *    nada; solo funcionaba `AuthController::uploadAvatar`, que escribe con query builder crudo
 *    (el builder NO pasa por fillable).
 *
 * Nota: `getFillable()` se verifica explícitamente porque el fallo original era SILENCIOSO —
 * un `update()` que no lanza nada y no escribe es justo lo que hace difícil de cazar este bug.
 */
class InductionAndAvatarFillableTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Induccion',
            'subdomain' => 'empresa-induccion',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador',
            'email' => 'colab.induccion@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
    }

    public function test_fillable_incluye_avatar_y_has_completed_induction(): void
    {
        $fillable = $this->makeUser()->getFillable();

        $this->assertContains('has_completed_induction', $fillable, 'sin esto, AcademyController no puede cerrar la inducción');
        $this->assertContains('avatar', $fillable, 'sin esto, los sync de avatar a users se descartan en silencio');
    }

    /**
     * El caso real: terminar la inducción debe PERSISTIR. Antes el update se tragaba el cambio.
     */
    public function test_marcar_induccion_completa_persiste(): void
    {
        $user = $this->makeUser();
        $this->assertFalse((bool) $user->has_completed_induction);

        $user->update(['has_completed_induction' => true]);

        $this->assertTrue(
            (bool) User::withoutGlobalScopes()->find($user->id)->has_completed_induction,
            'la inducción debe quedar marcada en users (el Reloj la lee para desbloquear al colaborador)'
        );
    }

    public function test_avatar_persiste_por_eloquent(): void
    {
        $user = $this->makeUser();

        $user->update(['avatar' => 'https://cdn.test/avatar.png']);

        $this->assertSame(
            'https://cdn.test/avatar.png',
            User::withoutGlobalScopes()->find($user->id)->avatar
        );
    }

    /**
     * El segundo bug apilado: el payload de /sync/state se construye desde `employees`, que NO tiene
     * la columna `has_completed_induction`. El frontend hace `{...currentUser, ...me}` con ese payload
     * (useAppStore:452), así que un `false` fantasma PISABA el valor bueno que vino de /me y el
     * colaborador volvía a "inducción incompleta" en cada refresco. El payload debe traer el valor real.
     */
    public function test_sync_state_devuelve_la_induccion_real_del_usuario(): void
    {
        $user = $this->makeUser();
        Employee::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'name' => 'Colaborador',
            'is_active_employee' => true,
        ]);
        $user->update(['has_completed_induction' => true]);

        $response = $this->actingAs($user->fresh())->getJson('/api/v1/sync/state');

        $response->assertStatus(200);
        $me = collect($response->json('users'))->firstWhere('id', $user->id);
        $this->assertNotNull($me, 'el usuario debe venir en el payload de /sync/state');
        $this->assertTrue(
            (bool) ($me['has_completed_induction'] ?? false),
            '/sync/state debe traer la inducción real de users; si no, el spread del front la pisa con false'
        );
    }
}
