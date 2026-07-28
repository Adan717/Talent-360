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

    public function test_professional_plan_registration_flow(): void
    {
        $user = User::factory()->create([
            'name' => 'Paloma Vega',
            'email' => 'dashcomputer@gmail.com',
            'tenant_id' => null,
            'role' => 'admin',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // 1. Submit professional plan registration
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/subscriptions/create-preference', [
                'company_name' => 'DashComputer',
                'subdomain' => 'dashcomputer',
                'plan' => 'pro',
                'employees' => 18,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertStatus(200);
        $initPoint = $response->json('init_point');
        $this->assertNotNull($initPoint);
        $this->assertStringContainsString('/api/v1/subscriptions/simulated-checkout', $initPoint);

        // 2. Fetch simulated checkout page
        $checkoutResponse = $this->get($initPoint);
        $checkoutResponse->assertStatus(200);
        $checkoutResponse->assertSee('DashComputer');
        $checkoutResponse->assertSee('$522');

        // 3. Confirm simulated payment
        $prefId = explode('pref_id=', $initPoint)[1];
        $confirmResponse = $this->get('/api/v1/subscriptions/simulated-confirm?pref_id=' . $prefId);
        
        $confirmResponse->assertRedirect();
        $this->assertDatabaseHas('tenants', [
            'subdomain' => 'dashcomputer',
            'name' => 'DashComputer',
            'plan' => 'pro',
            'max_users' => 18,
        ]);
    }
}

