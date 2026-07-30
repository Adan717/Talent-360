<?php

namespace Tests\Feature;

use App\Services\OfflineSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H20: el secreto offline cifrado debe CABER en su columna.
 *
 * `Crypt::encryptString()` devuelve 256 caracteres y la columna era `varchar(255)`. Postgres
 * rechazaba el INSERT; sqlite no aplica límites de longitud en VARCHAR, así que la suite lo daba
 * por bueno mientras el modo offline estaba muerto en el servidor.
 *
 * NOTA SOBRE ESTOS TESTS: el caso sólo MUERDE de verdad en Postgres. En sqlite pasan aunque la
 * columna siga siendo corta, porque sqlite guarda el valor entero igualmente. Por eso el primer
 * test no se limita a insertar: comprueba el tamaño DECLARADO de la columna, que sí es una
 * afirmación portable. Correr la suite con `phpunit.postgres.xml` es lo que cierra el hueco.
 */
class OfflineSecretCabeEnLaColumnaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_columna_no_impone_un_limite_menor_que_el_cifrado(): void
    {
        $largoReal = strlen(Crypt::encryptString(base64_encode(random_bytes(32))));

        $columna = DB::getSchemaBuilder()->getColumns('tenant_offline_secrets');
        $secret = collect($columna)->firstWhere('name', 'secret');

        $this->assertNotNull($secret, 'La columna `secret` debe existir.');

        // `text` no declara longitud; un varchar(N) con N < largoReal es el bug.
        if (preg_match('/\((\d+)\)/', $secret['type'] ?? '', $m)) {
            $this->assertGreaterThanOrEqual(
                $largoReal,
                (int) $m[1],
                "La columna admite {$m[1]} caracteres y el secreto cifrado ocupa {$largoReal}."
            );
        } else {
            $this->assertTrue(true); // text/varchar sin límite: correcto
        }
    }

    public function test_el_secreto_se_guarda_y_se_recupera_integro(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'Empresa', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $servicio = app(OfflineSignatureService::class);

        $primero = $servicio->getOrCreateCurrentSecret(1);
        $segundo = $servicio->getOrCreateCurrentSecret(1);

        $this->assertNotEmpty($primero['secret']);
        $this->assertSame($primero['secret'], $segundo['secret'],
            'El secreto debe ser estable entre llamadas: si se truncó al guardar, el descifrado falla o cambia.');

        // Y el valor guardado debe descifrar exactamente a lo que se entregó.
        $guardado = DB::table('tenant_offline_secrets')->where('tenant_id', 1)->value('secret');
        $this->assertSame($primero['secret'], Crypt::decryptString($guardado));
    }
}
