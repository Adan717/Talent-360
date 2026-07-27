<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantProvisionHijackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Empresa Existente',
            'subdomain' => 'existente',
            'public_slug' => 'existente',
            'plan' => 'pro',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_registration_cannot_reassign_an_existing_owners_tenant(): void
    {
        // Persona que YA es dueña de la empresa 1.
        $owner = User::factory()->create(['role' => 'admin', 'email' => 'dueno@empresa.com']);
        DB::table('users')->where('id', $owner->id)->update(['tenant_id' => 1]);

        // Alguien (sin sesión) intenta registrar una empresa nueva usando el correo del dueño.
        $response = $this->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'empresa-nueva',
            'plan' => 'freemium',
            'company_name' => 'Empresa Nueva',
            'admin_name' => 'Impostor',
            'admin_email' => 'dueno@empresa.com',
            'admin_password' => 'secret123',
        ]);

        $response->assertStatus(409);

        // El dueño sigue en su empresa original — no fue reasignado ni quedó huérfano.
        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'tenant_id' => 1,
            'email' => 'dueno@empresa.com',
        ]);
    }

    public function test_registration_with_a_brand_new_email_still_works(): void
    {
        $response = $this->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'empresa-limpia',
            'plan' => 'freemium',
            'company_name' => 'Empresa Limpia',
            'admin_name' => 'Nuevo Dueño',
            'admin_email' => 'nuevo@empresa-limpia.com',
            'admin_password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'nuevo@empresa-limpia.com']);
    }
}
