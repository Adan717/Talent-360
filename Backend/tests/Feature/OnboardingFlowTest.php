<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_authenticated_user_can_create_company(): void
    {
        // 1. User registers via Google / Social login (tenant_id is null)
        $user = User::factory()->create([
            'name' => 'Marisol Ramos Villafaña',
            'email' => 'marisoldecorarte@gmail.com',
            'tenant_id' => null,
            'role' => 'admin',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // 2. User submits step 2 (Company Details) on Freemium plan
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/subscriptions/create-preference', [
                'company_name' => 'DecorArte S.A. de C.V.',
                'subdomain' => 'decorarte',
                'plan' => 'freemium',
                'employees' => null,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('provisioned', true);
        $this->assertDatabaseHas('tenants', [
            'subdomain' => 'decorarte',
            'name' => 'DecorArte S.A. de C.V.',
        ]);
    }

    public function test_duplicate_subdomain_returns_422_validation_error(): void
    {
        Tenant::create([
            'name' => 'Decorarte Existente',
            'subdomain' => 'decorarte',
            'plan' => 'freemium',
            'max_users' => 5,
        ]);

        $user = User::factory()->create([
            'name' => 'Marisol Ramos Villafaña',
            'email' => 'marisoldecorarte2@gmail.com',
            'tenant_id' => null,
            'role' => 'admin',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/subscriptions/create-preference', [
                'company_name' => 'DecorArte S.A. de C.V.',
                'subdomain' => 'decorarte',
                'plan' => 'freemium',
                'employees' => null,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(422);
    }
}
