<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * updateProgress($id) escribía user_course_progress SIN validar que el curso exista, pese a
 * que course_id tiene FK a academy_courses (onDelete cascade). Un id fantasma → INSERT →
 * violación de FK (23503) → 500. Esto es exactamente lo que ocurría con el curso sintético
 * de Puntualidad (id 999) que el FE inyecta como fallback: al aprobarlo, el FE posteaba a
 * /academy/courses/999/progress → 500 → el .catch impedía resetear el contador local de
 * retardos → el checador quedaba BLOQUEADO PARA SIEMPRE tras 3 retardos.
 *
 * El fix valida la existencia del curso ANTES del write (igual que saveProgress con
 * exists:academy_courses,id), devolviendo 404 en vez de un 500 por FK. El desbloqueo real
 * del checador (contador client-side) se corrige en el FE (Academia.tsx), desacoplándolo
 * del POST condenado.
 */
class AcademyProgressGuardTest extends TestCase
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

    private function makeUser(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->refresh();
    }

    public function test_update_progress_on_nonexistent_course_returns_404_and_inserts_nothing(): void
    {
        $user = $this->makeUser();

        // id 999 = curso sintético de Puntualidad del FE; no existe en la BD.
        $response = $this->actingAs($user)->postJson('/api/v1/academy/courses/999/progress', [
            'status' => 'completed',
            'score' => 100,
        ]);

        // Antes: 500 por violación de FK (o inserción huérfana). Ahora: 404 limpio.
        $response->assertStatus(404);
        $this->assertDatabaseMissing('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => 999,
        ]);
    }

    public function test_update_progress_on_real_course_persists(): void
    {
        $user = $this->makeUser();

        $course = AcademyCourse::create([
            'title' => 'Curso de Puntualidad y Compromiso Laboral',
            'description' => 'Real, persistido en la BD.',
            'course_type' => 'training',
            'incentive_bonus_cents' => 0,
            'is_active' => true,
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/academy/courses/{$course->id}/progress", [
            'status' => 'completed',
            'score' => 100,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'completed',
        ]);
    }
}
