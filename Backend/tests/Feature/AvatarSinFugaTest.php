<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Avatares: sin fuga de nombres y sin 500 (ronda 2026-08-08).
 *
 * Dos cosas que salieron del mapa de la superficie del avatar:
 *
 *  1. El respaldo generado usaba el NOMBRE REAL como semilla
 *     (`api.dicebear.com/...?seed=Juan Pérez`). Como no hay ni un avatar subido en ninguna
 *     instancia, ése es el camino que se ejecuta SIEMPRE: cada carga del monitor le mandaba
 *     a un tercero la plantilla completa de la empresa, con IP y Referer.
 *  2. `platform_users` no tiene columna `avatar`, pero updateProfile y uploadAvatar la
 *     escribían ahí: 500 por columna inexistente para cualquier admin de plataforma que
 *     guardara su perfil. Es el mismo caso que el código ya documentaba haber arreglado
 *     para `phone`, y aquí seguía vivo.
 */
class AvatarSinFugaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $jefa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Avatar QA', 'subdomain' => 'avatarqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->jefa = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa QA',
            'email' => 'jefa@avatarqa.test', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    /**
     * Foto de prueba SIN la extensión GD.
     *
     * `UploadedFile::fake()->image()` genera la imagen con `imagejpeg()`, y el contenedor
     * donde corre la suite de Postgres no trae GD (el host sí): el mismo test pasaba en
     * sqlite y reventaba en Postgres con "imagejpeg function is not defined". `create()`
     * con el mime explícito no necesita GD y la regla `image` lo acepta igual.
     */
    private function fotoFalsa(): UploadedFile
    {
        return UploadedFile::fake()->create('yo.jpg', 12, 'image/jpeg');
    }

    public function test_el_monitor_no_manda_el_nombre_del_empleado_a_un_tercero(): void
    {
        $colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Juan Pérez Secreto',
            'email' => 'juan@avatarqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $colaborador->id,
            'name' => 'Juan Pérez Secreto', 'is_active_employee' => true,
        ]);
        // Un fichaje de hoy para que aparezca en el monitor.
        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $colaborador->id,
            'date' => now()->timezone('America/Mexico_City')->toDateString(),
            'type' => 'check_in', 'time' => '09:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($this->jefa)
            ->getJson('/api/v1/admin/dashboard/monitor')
            ->assertOk()
            ->json('data');

        $avatares = collect($data['users'])->pluck('avatar')->implode(' ');

        $this->assertStringNotContainsString('Juan', $avatares,
            'el nombre del colaborador no puede viajar en una URL hacia un tercero');
        $this->assertStringNotContainsString('Secreto', $avatares);
        $this->assertStringNotContainsString('P%C3%A9rez', $avatares, 'ni url-encodeado');
    }

    public function test_un_admin_de_plataforma_puede_guardar_su_perfil(): void
    {
        // `platform_users` NO tiene columna avatar: antes esto era un 500.
        $platformUser = PlatformUser::create([
            'name' => 'Soporte', 'email' => 'soporte@talent360.test',
            'password' => bcrypt('x'), 'role' => 'platform_admin', 'is_active' => true,
        ]);

        $this->actingAs($platformUser)
            ->postJson('/api/v1/me/update-profile', [
                'name' => 'Soporte Renombrado',
                'avatar' => 'https://ejemplo.test/foto.png',
            ])
            ->assertOk();

        $this->assertSame('Soporte Renombrado',
            DB::table('platform_users')->where('id', $platformUser->id)->value('name'));
    }

    public function test_a_una_cuenta_de_plataforma_se_le_dice_que_no_tiene_foto(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Soporte', 'email' => 'soporte2@talent360.test',
            'password' => bcrypt('x'), 'role' => 'platform_admin', 'is_active' => true,
        ]);

        // Ni 500 ni un "subido con éxito" que no guarda nada: se dice y ya.
        $this->actingAs($platformUser)
            ->post('/api/v1/me/upload-avatar', ['avatar' => $this->fotoFalsa()])
            ->assertStatus(422);
    }

    public function test_el_archivo_del_avatar_no_lleva_el_id_ni_la_hora(): void
    {
        $this->actingAs($this->jefa)
            ->post('/api/v1/me/upload-avatar', ['avatar' => $this->fotoFalsa()])
            ->assertOk();

        $url = DB::table('users')->where('id', $this->jefa->id)->value('avatar');

        // Antes: "avatar_{user_id}_{time()}.jpg" — con el id secuencial y una ventana de
        // tiempo pequeña, la plantilla entera se podía barrer probando nombres.
        $this->assertMatchesRegularExpression('#^/uploads/avatars/[0-9a-f-]{36}\.\w+$#', $url);
        $this->assertStringNotContainsString('avatar_' . $this->jefa->id, $url);

        @unlink(public_path(ltrim($url, '/')));
    }
}
