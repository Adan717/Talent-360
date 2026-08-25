<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CANDADO DE SEEDERS — los datos de siembra no pisan datos vivos (Fase 2, 2026-08-24).
 *
 * Un seeder es una herramienta de arranque: llena una base vacía para poder trabajar. El problema
 * es que nada le impedía correr contra la base de PRODUCCIÓN, donde ya hay empresas con gente
 * dentro. Los de Academia escriben cursos con su examen; correrlos sobre un cliente vivo le
 * reescribe evaluaciones que sus colaboradores ya presentaron, y de las que ya hay certificados
 * con folio verificable en la calle.
 *
 * Dos candados, y basta con que uno se cierre para detener la siembra:
 *
 *   1. **Entorno.** En producción no se siembra. Punto. Se puede forzar con `SEEDERS_PERMITIDOS=true`
 *      en el .env del servidor, que es un acto deliberado de quien administra la máquina y queda
 *      escrito — no algo que ocurre por teclear mal un comando de despliegue.
 *
 *   2. **Datos vivos.** Aunque el entorno lo permita, no se toca una empresa que ya tiene gente
 *      usando eso. Da igual el entorno: una base de pruebas con datos que alguien está usando
 *      para probar también merece no perderlos.
 *
 * El candado LANZA en vez de saltarse en silencio. Un seeder que "no hizo nada" sin decirlo es la
 * misma familia de defecto que esta ronda persigue: la pantalla dice una cosa y el sistema hace otra.
 */
class CandadoDeSeeders
{
    /**
     * @param  string  $queSiembra  Qué se está por sembrar, para que el mensaje sea útil.
     * @param  array<string,int|null>  $tablasVivas  [tabla => tenant_id o null para cualquier empresa]
     *                                que, si tienen filas, significan "aquí ya hay gente trabajando".
     */
    public static function verificar(string $queSiembra, array $tablasVivas = []): void
    {
        if (app()->environment('production') && !self::forzadoPorElAdministrador()) {
            throw new RuntimeException(
                "SEEDER BLOQUEADO ({$queSiembra}): esto es producción y los seeders reescriben datos "
                . 'de clientes vivos. Si de verdad hace falta, ponga SEEDERS_PERMITIDOS=true en el '
                . '.env del servidor, córralo, y quítelo.'
            );
        }

        foreach ($tablasVivas as $tabla => $tenantId) {
            $query = DB::table($tabla);
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            $filas = $query->count();
            if ($filas > 0) {
                $deQuien = $tenantId !== null ? "de la empresa {$tenantId}" : 'de alguna empresa';

                throw new RuntimeException(
                    "SEEDER BLOQUEADO ({$queSiembra}): hay {$filas} registro(s) {$deQuien} en `{$tabla}`. "
                    . 'Eso es trabajo real de alguien y un seeder no lo pisa. Siembre sobre una base '
                    . 'vacía o cree una empresa nueva.'
                );
            }
        }
    }

    private static function forzadoPorElAdministrador(): bool
    {
        // Se lee de config/ y no con env() directo: `env()` fuera de config devuelve null en
        // cuanto alguien cachea la configuración, y un candado que se abre solo al cachear la
        // config no es un candado. (Ya nos pasó con la llave del PAC.)
        return (bool) config('app.seeders_permitidos', false);
    }
}
