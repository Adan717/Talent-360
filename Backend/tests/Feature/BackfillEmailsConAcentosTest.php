<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H18 (tercera jornada de regresión 2026-07-30): los colaboradores dados de alta ANTES del fix
 * de H3 no pueden iniciar sesión. Nada de "incómodo": **bloqueo total**.
 *
 * H3 dejó de generar correos con diacríticos, pero no tocó los ya existentes. Al intentar entrar
 * como `adáncuéllar@pruebaqa360.com` desde el formulario real, el navegador rechaza el valor
 * antes de enviarlo — la validación nativa de `<input type="email">` no admite diacríticos en la
 * parte local:
 *
 *   > El texto seguido del signo "@" no debe incluir el símbolo "á".
 *
 * La petición NUNCA sale. Por API el login sí funciona (el backend compara bytes), y por eso el
 * problema pasó por "dato sucio" en la primera lectura; pero una persona entra por el formulario.
 * En una plantilla mexicana —Adán, José, María, Hernández, Muñoz— eso es media empresa fuera.
 *
 * La migración normaliza lo que quedó atrás. Lo delicado es la COLISIÓN: dos correos distintos
 * pueden normalizar al mismo (`josé@x` y `jose@x`). En ese caso el correo se deja INTACTO y se
 * reporta, porque pisar el correo de otra persona es peor que el bloqueo que se venía a arreglar.
 */
class BackfillEmailsConAcentosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email, string $nombre = 'Prueba'): int
    {
        return DB::table('users')->insertGetId([
            'tenant_id' => 1, 'name' => $nombre, 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'empleado',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Se invoca el `up()` directamente en vez de `artisan migrate`: `RefreshDatabase` ya corrió
     * TODAS las migraciones en el setUp —incluida ésta—, así que un `migrate` la vería aplicada
     * y no haría nada. Llamarla a mano es además lo que hace significativo el caso de correrla
     * dos veces.
     */
    private function correr(): void
    {
        (include base_path('database/migrations/2026_07_30_190000_backfill_emails_con_acentos.php'))->up();
    }

    public function test_el_correo_con_acentos_queda_normalizado(): void
    {
        $id = $this->usuario('adáncuéllar@pruebaqa360.com', 'Adán Cuéllar');

        $this->correr();

        $this->assertSame('adancuellar@pruebaqa360.com', DB::table('users')->where('id', $id)->value('email'));
    }

    public function test_el_expediente_se_mueve_junto_con_el_usuario(): void
    {
        $id = $this->usuario('maríañez@x.com', 'María Ñez');
        DB::table('employees')->insert([
            'tenant_id' => 1, 'user_id' => $id, 'name' => 'María Ñez',
            'email' => 'maríañez@x.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->correr();

        $this->assertSame('marianez@x.com', DB::table('users')->where('id', $id)->value('email'));
        $this->assertSame('marianez@x.com', DB::table('employees')->where('user_id', $id)->value('email'),
            'El expediente no puede quedar apuntando al correo viejo.');
    }

    public function test_los_correos_ya_limpios_no_se_tocan(): void
    {
        $id = $this->usuario('joseramirez@x.com');
        $antes = DB::table('users')->where('id', $id)->value('updated_at');

        $this->correr();

        $this->assertSame('joseramirez@x.com', DB::table('users')->where('id', $id)->value('email'));
        $this->assertSame($antes, DB::table('users')->where('id', $id)->value('updated_at'),
            'Una fila que no cambia no debe reescribirse.');
    }

    public function test_una_colision_deja_el_correo_intacto(): void
    {
        // Lo peligroso: si `josé@x.com` se normalizara, chocaría con el `jose@x.com` que ya existe.
        $limpio = $this->usuario('jose@x.com', 'Jose Uno');
        $acentuado = $this->usuario('josé@x.com', 'José Dos');

        $this->correr();

        $this->assertSame('jose@x.com', DB::table('users')->where('id', $limpio)->value('email'));
        $this->assertSame('josé@x.com', DB::table('users')->where('id', $acentuado)->value('email'),
            'Ante una colisión se conserva el correo: pisar el de otra persona es peor.');
    }

    public function test_correr_dos_veces_no_cambia_nada(): void
    {
        $id = $this->usuario('adáncuéllar@x.com');

        $this->correr();
        $primera = DB::table('users')->where('id', $id)->value('email');
        $this->correr();

        $this->assertSame($primera, DB::table('users')->where('id', $id)->value('email'));
        $this->assertSame('adancuellar@x.com', $primera);
    }

    public function test_dos_tenants_pueden_tener_el_mismo_correo_normalizado(): void
    {
        // La colisión sólo importa si de verdad choca. `users.email` es único GLOBAL en este
        // esquema, así que este caso se comporta como colisión y ninguno debe pisarse.
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otra empresa', 'subdomain' => 'otra',
            'plan' => 'basic', 'max_users' => 5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $a = $this->usuario('josé@x.com', 'Jose A');
        $b = DB::table('users')->insertGetId([
            'tenant_id' => 2, 'name' => 'Jose B', 'email' => 'jose@x.com',
            'password' => bcrypt('x'), 'role' => 'empleado',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->correr();

        $this->assertSame('josé@x.com', DB::table('users')->where('id', $a)->value('email'));
        $this->assertSame('jose@x.com', DB::table('users')->where('id', $b)->value('email'));
    }
}
