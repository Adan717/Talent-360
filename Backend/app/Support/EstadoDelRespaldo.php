<?php

namespace App\Support;

/**
 * ¿Hubo respaldo anoche? — la única respuesta que la aplicación puede dar sin adivinar.
 *
 * El respaldo (docs/RESPALDO_Y_RESTAURACION.md) corre en el HOST por cron: los dumps viven en
 * /root/respaldos/auto y el contenedor sólo monta ./Backend, así que la aplicación no puede
 * mirarlos ni contarlos. Por eso el script deja una MARCA dentro de storage/app —el único
 * terreno común— y aquí sólo se lee esa marca.
 *
 * Regla dura: **una marca ausente o ilegible cuenta como FALLO**. Un respaldo que la aplicación
 * no puede confirmar no existe para nadie; el silencio es exactamente lo que hay que gritar,
 * porque es lo que se veía el día que alguien reinstala el servidor y nadie vuelve a poner el
 * cron. (Ver deploy_v2.sh, que ahora lo instala solo.)
 *
 * No toca la base de datos A PROPÓSITO: quien la consulta es /api/health, que debe seguir
 * contestando —y diciendo la verdad— justamente cuando Postgres está caído.
 */
class EstadoDelRespaldo
{
    /**
     * Ventana máxima entre respaldos, en horas. El cron es diario (02:45 UTC): 24 h de ciclo
     * más 2 h de margen para que un respaldo lento, o un reinicio del servidor a esa hora, no
     * dispare la alarma antes de haber perdido de verdad un día.
     */
    public const HORAS_MAXIMAS = 26;

    /** Dónde la deja el script del host. Escrita por root, leída por www-data (644). */
    public static function ruta(): string
    {
        return storage_path('app/respaldo/ultimo.json');
    }

    /**
     * @return array{ok: bool, horas: float|null, ultimo_utc: string|null, motivo: string|null}
     *         motivo: null si está bien; si no, 'sin_marca' | 'ilegible' | 'viejo'.
     */
    public static function revisar(?\DateTimeInterface $ahora = null): array
    {
        $ruta = self::ruta();

        if (!is_file($ruta)) {
            return self::fallo('sin_marca');
        }

        $crudo = @file_get_contents($ruta);
        if ($crudo === false) {
            // Existe pero no se puede leer: casi siempre permisos (la escribe root, la lee
            // www-data). Es un fallo distinto de "no hay respaldo", pero igual de ciego.
            return self::fallo('ilegible');
        }

        $marca = json_decode($crudo, true);
        if (!is_array($marca) || !isset($marca['terminado_utc']) || !is_string($marca['terminado_utc'])) {
            return self::fallo('ilegible');
        }

        $terminado = self::instante($marca['terminado_utc']);
        if ($terminado === null) {
            return self::fallo('ilegible');
        }

        $ahora ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Por marcas de tiempo crudas: ni una zona horaria mal puesta ni el cambio de semántica
        // de diff() entre versiones de Carbon pueden torcer esta cuenta.
        //
        // Se DECIDE con el valor exacto y sólo se REDONDEA para contarlo. Al revés —redondear y
        // luego comparar— los primeros 18 segundos pasado el límite se redondean a 26.00 y el
        // respaldo viejo se declara bueno: un umbral que no es el que dice ser.
        $exactas = ($ahora->getTimestamp() - $terminado->getTimestamp()) / 3600;
        $horas = round($exactas, 2);
        $ultimo = $terminado->format('Y-m-d\TH:i:s\Z');

        // Exactamente HORAS_MAXIMAS todavía pasa; el umbral es "más de".
        if ($exactas > self::HORAS_MAXIMAS) {
            return ['ok' => false, 'horas' => $horas, 'ultimo_utc' => $ultimo, 'motivo' => 'viejo'];
        }

        // Un valor negativo significa marca en el futuro (reloj del host adelantado). No se
        // castiga: lo que se vigila es que no falte un respaldo, no que sobre.
        return ['ok' => true, 'horas' => $horas, 'ultimo_utc' => $ultimo, 'motivo' => null];
    }

    /** @return array{ok: bool, horas: null, ultimo_utc: null, motivo: string} */
    private static function fallo(string $motivo): array
    {
        return ['ok' => false, 'horas' => null, 'ultimo_utc' => null, 'motivo' => $motivo];
    }

    /**
     * Fecha ISO en UTC, o null si no lo es.
     *
     * El filtro por regex NO es decoración: `new DateTimeImmutable('')` devuelve AHORA, así que
     * una marca vacía o truncada se leería como un respaldo recién hecho — el peor error posible
     * en esta clase, porque miente en la dirección tranquilizadora.
     */
    private static function instante(string $valor): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $valor)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($valor, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
