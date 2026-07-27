<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreemiumComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(int $id, string $plan): void
    {
        // El seed de migración ya crea el tenant 1 como 'pro', así que insertOrIgnore no
        // basta — se fija el plan explícitamente después.
        DB::table('tenants')->insertOrIgnore([
            'id' => $id, 'name' => "T{$id}", 'subdomain' => "t{$id}", 'plan' => $plan,
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenants')->where('id', $id)->update(['plan' => $plan]);
    }

    private function makeAdmin(int $tenantId): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        $user->refresh();
        return $user;
    }

    public function test_freemium_tenant_can_submit_proof(): void
    {
        $this->makeTenant(1, 'freemium');
        $admin = $this->makeAdmin(1);

        $response = $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', [
            'period' => '2026-08',
            'proof_note' => 'Compartí la publicación en Facebook e Instagram.',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('freemium_compliance_checks', [
            'tenant_id' => 1, 'period' => '2026-08', 'status' => 'submitted',
        ]);
    }

    public function test_non_freemium_tenant_cannot_submit(): void
    {
        $this->makeTenant(1, 'pro');
        $admin = $this->makeAdmin(1);

        $response = $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', [
            'proof_note' => 'x',
        ]);

        $response->assertStatus(422);
    }

    public function test_submit_requires_a_note_or_url(): void
    {
        $this->makeTenant(1, 'freemium');
        $admin = $this->makeAdmin(1);

        $response = $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', [
            'period' => '2026-08',
        ]);

        $response->assertStatus(422);
    }

    public function test_resubmitting_updates_the_same_period_row(): void
    {
        $this->makeTenant(1, 'freemium');
        $admin = $this->makeAdmin(1);

        $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', ['period' => '2026-08', 'proof_note' => 'v1']);
        $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', ['period' => '2026-08', 'proof_note' => 'v2']);

        $this->assertEquals(1, DB::table('freemium_compliance_checks')->where('tenant_id', 1)->where('period', '2026-08')->count());
        $this->assertDatabaseHas('freemium_compliance_checks', ['tenant_id' => 1, 'period' => '2026-08', 'proof_note' => 'v2']);
    }

    public function test_platform_can_list_and_review(): void
    {
        $this->makeTenant(1, 'freemium');
        $admin = $this->makeAdmin(1);
        $this->actingAs($admin)->postJson('/api/v1/me/freemium-compliance', ['period' => '2026-08', 'proof_note' => 'listo']);

        $platformAdmin = User::factory()->create(['role' => 'platform_admin']);

        $list = $this->actingAs($platformAdmin)->getJson('/api/v1/platform/freemium-compliance?status=submitted');
        $list->assertStatus(200);
        $checkId = $list->json('checks.0.id');
        $this->assertNotNull($checkId);

        $review = $this->actingAs($platformAdmin)->postJson("/api/v1/platform/freemium-compliance/{$checkId}/review", [
            'status' => 'approved',
            'review_note' => 'Verificado.',
        ]);
        $review->assertStatus(200);

        $this->assertDatabaseHas('freemium_compliance_checks', [
            'id' => $checkId, 'status' => 'approved', 'reviewed_by' => $platformAdmin->id,
        ]);
    }

    public function test_compliance_is_scoped_to_tenant(): void
    {
        $this->makeTenant(1, 'freemium');
        $this->makeTenant(2, 'freemium');
        $adminA = $this->makeAdmin(1);
        $adminB = $this->makeAdmin(2);

        $this->actingAs($adminA)->postJson('/api/v1/me/freemium-compliance', ['period' => '2026-08', 'proof_note' => 'A']);
        $this->actingAs($adminB)->postJson('/api/v1/me/freemium-compliance', ['period' => '2026-08', 'proof_note' => 'B']);

        $mine = $this->actingAs($adminA)->getJson('/api/v1/me/freemium-compliance');
        $mine->assertStatus(200);
        $notes = collect($mine->json('checks'))->pluck('proof_note')->all();
        $this->assertContains('A', $notes);
        $this->assertNotContains('B', $notes);
    }
}
