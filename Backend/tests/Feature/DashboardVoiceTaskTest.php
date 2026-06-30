<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardVoiceTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Default Tenant
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

    public function test_can_parse_voice_task_text_matching_employee_name(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        // Create target employee Francisco
        $employee = User::factory()->create([
            'name' => 'Francisco Javier',
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => 'Francisco Javier',
            'email' => $employee->email,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/dashboard/parse-voice-task', [
            'text' => 'Asigna a Francisco la tarea de limpiar los cristales de la entrada en 45 minutos con prioridad alta'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'title' => 'Limpiar los cristales de la entrada con prioridad alta',
                'estimated_mins' => 45,
                'priority' => 'high',
                'category' => 'limpieza',
                'target_type' => 'user',
                'target_id' => $employee->id,
                'matched_name' => 'Francisco Javier',
                'time_detected' => true,
                'assistant_type' => 'ninguno',
                'assistant_detected' => false,
            ]
        ]);
    }

    public function test_can_parse_voice_task_text_matching_job_role(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        // Create job role
        $jobRole = JobRole::create([
            'name' => 'Cajeros',
            'area' => 'Cajas',
            'tenant_id' => 1,
            'esAperturador' => false,
            'tiempoTolerancia' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/dashboard/parse-voice-task', [
            'text' => 'Asignar a Cajeros la tarea de hacer arqueo de caja de una hora'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'title' => 'Hacer arqueo de caja',
                'estimated_mins' => 60,
                'priority' => 'normal',
                'category' => 'administrativo',
                'target_type' => 'role',
                'target_id' => $jobRole->id,
                'matched_name' => 'Cajeros',
                'time_detected' => true,
            ]
        ]);
    }

    public function test_can_create_task_directly_assigned_to_user(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        // Target employee
        $employee = User::factory()->create([
            'name' => 'Francisco Javier',
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => 'Francisco Javier',
            'email' => $employee->email,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/dashboard/create-task', [
            'title' => 'Limpiar cristales',
            'estimated_mins' => 30,
            'points' => 10,
            'priority' => 'normal',
            'category' => 'limpieza',
            'target_type' => 'user',
            'target_id' => $employee->id,
            'assistant_type' => 'ninguno',
        ]);

        $response->assertStatus(200);
        $taskId = $response->json('data.id');

        // Verify task was created
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'title' => 'Limpiar cristales',
            'target_type' => 'user',
            'target_id' => $employee->id,
        ]);

        // Verify assignment was created directly to Francisco (user_id = employee->id)
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $taskId,
            'user_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_can_parse_voice_task_with_assistant_type_and_prompt(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/dashboard/parse-voice-task', [
            'text' => 'Crear tarea Limpiar la mesa de comida con asistente de foto pidiendo tomar foto de la mesa limpia'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'title' => 'Limpiar la mesa de comida',
                'assistant_type' => 'evidencia_foto',
                'assistant_prompt' => 'Tomar foto de la mesa limpia',
                'assistant_detected' => true,
            ]
        ]);
    }
}
