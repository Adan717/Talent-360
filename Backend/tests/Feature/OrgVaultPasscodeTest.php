<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Seguridad (production-readiness): passcodes de la Wiki pública POR TENANT y hasheados.
 *
 * Antes eran HARDCODEADOS y GLOBALES (`Guru28`, `55`, …) en el controlador → cualquiera con `Guru28`
 * reescribía la wiki de CUALQUIER tenant vía rutas públicas. Ahora: passcode por tenant, hasheado,
 * fail-closed (sin passcode configurado → flujo público deshabilitado), admin ⊇ viewer.
 */
class OrgVaultPasscodeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug, ?string $adminPin = null, ?string $viewerPin = null): Tenant
    {
        $t = Tenant::create([
            'name' => 'Empresa ' . $slug, 'subdomain' => $slug, 'public_slug' => $slug,
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $t->org_vault_admin_passcode_hash = $adminPin ? Hash::make($adminPin) : null;
        $t->org_vault_viewer_passcode_hash = $viewerPin ? Hash::make($viewerPin) : null;
        $t->save();
        return $t;
    }

    private function makeDocWithSuggestion(Tenant $tenant): array
    {
        $docId = DB::table('obsidian_documents')->insertGetId([
            'tenant_id' => $tenant->id, 'slug' => 'reglamento', 'filename' => 'reglamento.md',
            'title' => 'Reglamento', 'raw_content' => 'ORIGINAL', 'type' => 'nota',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sugId = DB::table('obsidian_suggestions')->insertGetId([
            'tenant_id' => $tenant->id, 'document_id' => $docId, 'author_name' => 'Anon',
            'original_content' => 'ORIGINAL', 'proposed_content' => 'HACKEADO', 'comment' => 'x',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['doc_id' => $docId, 'sug_id' => $sugId];
    }

    private function approveUrl(string $slug, int $sugId): string
    {
        return "/api/v1/public/org-vault/{$slug}/suggestions/{$sugId}/approve";
    }

    // ---- El hueco cerrado -----------------------------------------------------------

    public function test_el_passcode_hardcodeado_viejo_ya_no_sirve(): void
    {
        $t = $this->makeTenant('acme', adminPin: 'realsecret1');
        $ids = $this->makeDocWithSuggestion($t);

        // 'Guru28' era el admin passcode global hardcodeado. Ya no debe funcionar.
        $this->postJson($this->approveUrl('acme', $ids['sug_id']), ['passcode' => 'Guru28'])
            ->assertStatus(403);
        // El doc NO fue reescrito.
        $this->assertSame('ORIGINAL', DB::table('obsidian_documents')->where('id', $ids['doc_id'])->value('raw_content'));
    }

    public function test_fail_closed_sin_passcode_configurado(): void
    {
        $t = $this->makeTenant('sinpin'); // ningún passcode configurado
        $ids = $this->makeDocWithSuggestion($t);

        // Con cualquier passcode → 403 (fail-closed). Antes 'Guru28' habría entrado.
        foreach (['Guru28', '55', 'loquesea'] as $intento) {
            $this->postJson($this->approveUrl('sinpin', $ids['sug_id']), ['passcode' => $intento])
                ->assertStatus(403);
        }
        // Passcode vacío falla la validación required → 422 (no 403), pero tampoco aprueba.
        $this->postJson($this->approveUrl('sinpin', $ids['sug_id']), ['passcode' => ''])->assertStatus(422);
        $this->assertSame('ORIGINAL', DB::table('obsidian_documents')->where('id', $ids['doc_id'])->value('raw_content'));
    }

    public function test_con_el_admin_passcode_del_tenant_si_aprueba(): void
    {
        $t = $this->makeTenant('acme', adminPin: 'realsecret1');
        $ids = $this->makeDocWithSuggestion($t);

        $this->postJson($this->approveUrl('acme', $ids['sug_id']), ['passcode' => 'realsecret1'])
            ->assertStatus(200);
        $this->assertSame('HACKEADO', DB::table('obsidian_documents')->where('id', $ids['doc_id'])->value('raw_content')); // se aplicó la propuesta
    }

    public function test_el_passcode_de_otro_tenant_no_sirve(): void
    {
        $a = $this->makeTenant('empresa-a', adminPin: 'secreto-a1');
        $this->makeTenant('empresa-b', adminPin: 'secreto-b1');
        $ids = $this->makeDocWithSuggestion($a);

        // El passcode de la empresa B NO debe aprobar en la empresa A.
        $this->postJson($this->approveUrl('empresa-a', $ids['sug_id']), ['passcode' => 'secreto-b1'])
            ->assertStatus(403);
        $this->assertSame('ORIGINAL', DB::table('obsidian_documents')->where('id', $ids['doc_id'])->value('raw_content'));
    }

    public function test_el_viewer_passcode_no_puede_aprobar(): void
    {
        $t = $this->makeTenant('acme', adminPin: 'admin-secret1', viewerPin: 'viewer-secret1');
        $ids = $this->makeDocWithSuggestion($t);

        // El passcode viewer sirve para ver/proponer, NO para aprobar (que muta el doc).
        $this->postJson($this->approveUrl('acme', $ids['sug_id']), ['passcode' => 'viewer-secret1'])
            ->assertStatus(403);
    }

    public function test_el_admin_passcode_tambien_habilita_operaciones_viewer(): void
    {
        $t = $this->makeTenant('acme', adminPin: 'admin-secret1', viewerPin: 'viewer-secret1');

        // validate-passcode (tier viewer): el admin passcode entra y reporta rol 'auditor'.
        $res = $this->postJson("/api/v1/public/org-vault/acme/validate-passcode", ['passcode' => 'admin-secret1']);
        $res->assertStatus(200)->assertJson(['valid' => true, 'role' => 'auditor']);

        // El viewer passcode entra como 'colaborador'.
        $this->postJson("/api/v1/public/org-vault/acme/validate-passcode", ['passcode' => 'viewer-secret1'])
            ->assertStatus(200)->assertJson(['valid' => true, 'role' => 'colaborador']);
    }

    // ---- El admin fija los passcodes -------------------------------------------------

    public function test_el_admin_fija_el_passcode_y_se_guarda_hasheado(): void
    {
        $t = $this->makeTenant('acme');
        $admin = User::create([
            'tenant_id' => $t->id, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);

        $res = $this->actingAs($admin)->postJson('/api/v1/org-vault/passcodes', [
            'admin_passcode' => 'nuevo-admin1', 'viewer_passcode' => 'nuevo-viewer1',
        ]);

        $res->assertStatus(200)->assertJson(['success' => true, 'admin_passcode_set' => true, 'viewer_passcode_set' => true]);
        $t->refresh();
        $this->assertNotSame('nuevo-admin1', $t->org_vault_admin_passcode_hash, 'NO en claro');
        $this->assertTrue(Hash::check('nuevo-admin1', $t->org_vault_admin_passcode_hash));
        $this->assertStringNotContainsString('nuevo-admin1', $res->getContent(), 'la respuesta nunca devuelve el passcode');
    }

    public function test_un_empleado_no_puede_fijar_passcodes(): void
    {
        $t = $this->makeTenant('acme');
        $empleado = User::create([
            'tenant_id' => $t->id, 'name' => 'Emp', 'email' => 'e' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        $this->actingAs($empleado)->postJson('/api/v1/org-vault/passcodes', ['admin_passcode' => 'x123456'])
            ->assertStatus(403); // la ruta está en el grupo role:admin,supervisor
    }

    public function test_el_hash_del_passcode_no_se_filtra_en_json(): void
    {
        $t = $this->makeTenant('acme', adminPin: 'secreto1');
        $this->assertArrayNotHasKey('org_vault_admin_passcode_hash', $t->fresh()->toArray());
    }
}
