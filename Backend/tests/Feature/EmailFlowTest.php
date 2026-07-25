<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitation;
use App\Mail\PasswordResetMail;
use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenants')->where('id', 1)->update(['plan' => 'enterprise', 'name' => 'DecorArte']);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->refresh();
    }

    public function test_creating_employee_with_email_sends_invitation(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $jobRole = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);

        $response = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Nuevo Colaborador',
            'email' => 'colab@empresa.com',
            'role' => 'empleado',
            'job_role_id' => $jobRole->id,
        ]);

        $response->assertStatus(201);
        Mail::assertSent(EmployeeInvitation::class, function ($mail) {
            return $mail->hasTo('colab@empresa.com');
        });
    }

    public function test_resend_invitation_endpoint_sends_email(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1, 'user_id' => null, 'name' => 'Con Correo', 'email' => 'con@correo.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/employees/{$employeeId}/resend-invitation");

        $response->assertStatus(200);
        Mail::assertSent(EmployeeInvitation::class);
        // El PIN de activación quedó regenerado en el empleado.
        $this->assertNotNull(DB::table('employees')->where('id', $employeeId)->value('pin_code'));
    }

    public function test_resend_invitation_without_email_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1, 'user_id' => null, 'name' => 'Sin Correo', 'email' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/employees/{$employeeId}/resend-invitation");
        $response->assertStatus(422);
    }

    public function test_forgot_password_sends_email_for_existing_account_and_is_generic(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'existe@x.com', 'role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $response = $this->postJson('/api/v1/forgot-password', ['email' => 'existe@x.com']);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        Mail::assertSent(PasswordResetMail::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'existe@x.com']);
    }

    public function test_forgot_password_for_unknown_email_still_returns_success_without_sending(): void
    {
        Mail::fake();
        $response = $this->postJson('/api/v1/forgot-password', ['email' => 'noexiste@x.com']);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]); // no revela que no existe
        Mail::assertNothingSent();
    }

    public function test_reset_password_with_valid_token_updates_password(): void
    {
        $user = User::factory()->create(['email' => 'reset@x.com', 'password' => Hash::make('viejo123'), 'role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $plainToken = 'token-plano-de-prueba';
        DB::table('password_reset_tokens')->insert([
            'email' => 'reset@x.com', 'token' => Hash::make($plainToken), 'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'email' => 'reset@x.com', 'token' => $plainToken, 'password' => 'nuevo123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('nuevo123', User::withoutGlobalScopes()->find($user->id)->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'reset@x.com']);
    }

    public function test_reset_password_with_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'reset2@x.com', 'role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'reset2@x.com', 'token' => Hash::make('el-correcto'), 'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'email' => 'reset2@x.com', 'token' => 'el-incorrecto', 'password' => 'nuevo123',
        ]);

        $response->assertStatus(422);
    }

    public function test_platform_email_config_get_and_set(): void
    {
        $platformAdmin = User::factory()->create(['role' => 'platform_admin']);

        $save = $this->actingAs($platformAdmin)->putJson('/api/v1/platform/email-settings', [
            'platform_sender_email' => 'notificaciones@talent360.mx',
            'platform_welcome_email_subject' => '¡Bienvenido!',
        ]);
        $save->assertStatus(200);

        $get = $this->actingAs($platformAdmin)->getJson('/api/v1/platform/email-settings');
        $get->assertStatus(200);
        $get->assertJson([
            'platform_sender_email' => 'notificaciones@talent360.mx',
            'platform_welcome_email_subject' => '¡Bienvenido!',
        ]);
    }

    public function test_tenant_email_config_get_and_set(): void
    {
        $admin = $this->makeAdmin();

        $save = $this->actingAs($admin)->putJson('/api/v1/company/email-settings', [
            'sender_display_name' => 'DecorArte 360',
            'sender_reply_to' => 'contacto@decorarte.com',
        ]);
        $save->assertStatus(200);

        $get = $this->actingAs($admin)->getJson('/api/v1/company/email-settings');
        $get->assertStatus(200);
        $get->assertJson([
            'sender_display_name' => 'DecorArte 360',
            'sender_reply_to' => 'contacto@decorarte.com',
        ]);
    }
}
