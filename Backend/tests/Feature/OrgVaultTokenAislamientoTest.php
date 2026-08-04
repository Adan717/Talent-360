<?php

namespace Tests\Feature;

use App\Models\ObsidianUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia AC7/AC8 (auditoría del módulo, 2026-08-04) — el agujero más grave encontrado.
 *
 * La Wiki pública ("La Receta Secreta" / org-vault) deja que cualquiera se registre sin
 * sesión con solo saber el slug público de la empresa, y le devuelve un token. Ese token
 * era un token Sanctum normal y el guard `sanctum` de este proyecto no fija `provider`,
 * así que `hasValidProvider()` lo daba por bueno: el token ABRÍA LA API PRIVADA. Medido
 * con una sonda antes del arreglo, un desconocido conseguía:
 *
 *   POST /public/org-vault/{slug}/register  → 200, role='admin' (bastaba mandar el
 *                                             job_role_id del puesto llamado "Administrador")
 *   GET  /api/v1/sync/state                 → 200 (estado operativo completo de la empresa)
 *   POST /api/v1/employees                  → 201 (ALTA de colaboradores)
 *   PUT  /api/v1/company/payroll-settings   → 200 (config de NÓMINA)
 *
 * Tres candados, uno por capa:
 *   1. El guard rechaza los tokens de `ObsidianUser` (AppServiceProvider).
 *   2. El registro público nace SIEMPRE como colaborador; el rol admin solo por publicLogin,
 *      que verifica la contraseña real.
 *   3. El token del vault solo vale en la Wiki de SU empresa (resolvePublicUser).
 */
class OrgVaultTokenAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private function prepararEmpresa(int $id, string $slug, string $nombre): void
    {
        if (DB::table('tenants')->where('id', $id)->exists()) {
            DB::table('tenants')->where('id', $id)->update([
                'name' => $nombre,
                'subdomain' => $slug,
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('tenants')->insert([
            'id' => $id,
            'name' => $nombre,
            'subdomain' => $slug,
            'plan' => 'pro',
            'max_users' => 50,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registrarPublico(string $slug, string $email, ?int $jobRoleId = null)
    {
        return $this->postJson("/api/v1/public/org-vault/{$slug}/register", array_filter([
            'name' => 'Intruso Anonimo',
            'email' => $email,
            'password' => 'hola1234',
            'job_role_id' => $jobRoleId,
        ]));
    }

    public function test_el_registro_publico_no_puede_concederse_el_rol_admin(): void
    {
        $this->prepararEmpresa(1, 'victima', 'Empresa Victima');

        $roleId = DB::table('job_roles')->insertGetId([
            'tenant_id' => 1,
            'name' => 'Administrador General',
            'area' => 'Direccion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registro = $this->registrarPublico('victima', 'intruso@example.com', $roleId);

        $registro->assertStatus(200);
        // Antes: 'admin' — y con eso se aprobaban propuestas y se reescribía el manual.
        $this->assertSame('colaborador', $registro->json('user.role'));
        $this->assertSame('colaborador', ObsidianUser::withoutGlobalScopes()
            ->where('email', 'intruso@example.com')->first()->role);
    }

    public function test_el_token_del_vault_no_abre_la_api_privada(): void
    {
        $this->prepararEmpresa(1, 'victima', 'Empresa Victima');

        $token = $this->registrarPublico('victima', 'intruso@example.com')->json('token');
        $this->assertNotNull($token, 'la Wiki sigue emitiendo su token');

        $cabeceras = ['Authorization' => 'Bearer ' . $token];

        // Lectura: el estado operativo de la empresa y su plantilla.
        $this->getJson('/api/v1/sync/state', $cabeceras)->assertStatus(401);
        $this->getJson('/api/v1/employees', $cabeceras)->assertStatus(401);
        $this->getJson('/api/v1/academy/courses', $cabeceras)->assertStatus(401);

        // Escritura: alta de personal y configuración de nómina.
        $this->postJson('/api/v1/employees', [
            'name' => 'Empleado Fantasma',
            'email' => 'fantasma@example.com',
            'password' => 'password123',
            'role' => 'empleado',
            'contract_type' => 'Tiempo Completo',
            'salary' => 9000,
            'is_active' => true,
        ], $cabeceras)->assertStatus(401);

        $this->putJson('/api/v1/company/payroll-settings', [
            'week_start_day' => 3,
            'pay_day' => 5,
            'calc_time' => '01:00',
        ], $cabeceras)->assertStatus(401);

        $this->assertDatabaseMissing('users', ['email' => 'fantasma@example.com']);
    }

    public function test_el_token_de_una_empresa_no_vale_en_la_wiki_de_otra(): void
    {
        $this->prepararEmpresa(1, 'empresa-a', 'Empresa A');
        $this->prepararEmpresa(2, 'empresa-b', 'Empresa B');

        // Admin legítimo de la Wiki de la empresa A (el rol se pone a mano: por la puerta
        // pública ya no se puede, que es justo lo que fija el primer test).
        $adminA = ObsidianUser::create([
            'tenant_id' => 1,
            'name' => 'Admin A',
            'email' => 'admin@empresa-a.test',
            'password' => 'password123',
            'role' => 'admin',
        ]);
        $token = $adminA->createToken('vault-user-token', ['org-vault'])->plainTextToken;

        $cabeceras = ['Authorization' => 'Bearer ' . $token];

        // En SU empresa manda: la lista de propuestas le responde (vacía, pero autorizada).
        $this->postJson('/api/v1/public/org-vault/empresa-a/suggestions', [], $cabeceras)
            ->assertStatus(200);

        // En la Wiki de la empresa B, no. Antes: 200, con acceso a sus propuestas.
        $this->postJson('/api/v1/public/org-vault/empresa-b/suggestions', [], $cabeceras)
            ->assertStatus(403);
    }
}
