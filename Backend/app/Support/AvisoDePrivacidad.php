<?php

namespace App\Support;

use App\Models\PrivacyConsent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * El aviso de privacidad, en un solo sitio (2026-09-05).
 *
 * POR QUÉ EXISTE: hasta hoy la casilla "acepto el Aviso de Privacidad" del alta de empresa era
 * TEATRO — el valor nunca salía del navegador (`acceptedTerms` en SaaSLandingPage sólo habilitaba
 * el botón) y no había ni tabla ni endpoint que lo guardara. Ante la LFPDPPP, un consentimiento
 * que no se puede probar es un consentimiento que no existe.
 *
 * LA VERSIÓN VIVE AQUÍ Y SÓLO AQUÍ. El texto legal es del abogado y vive en el frontend
 * (`LegalModal.tsx`, copia única que consumen el modal y la página pública `/privacidad`); lo que
 * este proyecto registra es la ACEPTACIÓN de una versión concreta de ese texto. El servidor nunca
 * acepta la versión que le diga el cliente: la estampa él. Si el abogado cambia el texto, se sube
 * VERSION aquí y todo el mundo vuelve a aceptar — eso es justamente lo que hace útil el registro.
 *
 * El candado contra la deriva entre las dos mitades (fecha impresa en el texto vs. constante) es
 * `AvisoDePrivacidadTest::test_la_version_declarada_coincide_con_la_fecha_del_texto_legal`.
 */
final class AvisoDePrivacidad
{
    /** Versión VIGENTE del aviso. Coincide con la "Última actualización" impresa en LegalModal.tsx. */
    public const VERSION = '2026-07-24';

    /** La misma fecha como la lee una persona (es la que aparece dentro del texto legal). */
    public const FECHA_LEGIBLE = '24 de Julio de 2026';

    /** Dónde se lee el aviso completo, sin sesión. */
    public const RUTA_PUBLICA = '/privacidad';

    /** Los tres momentos en que se pide el consentimiento. */
    public const PUNTO_ALTA_EMPRESA = 'alta_empresa';
    public const PUNTO_PRIMER_INGRESO = 'primer_ingreso';
    public const PUNTO_POSTULACION = 'postulacion';

    /**
     * ¿A esta cuenta hay que pedirle el consentimiento antes de dejarla trabajar?
     *
     * Lee la MARCA de la cuenta (`users.privacidad_pendiente`), no la tabla de constancias: es el
     * mismo diseño de `must_change_password` —una marca que se pone al crear la cuenta y se quita
     * al aceptar— y por la misma razón, que el candado se evalúa en CADA petición.
     *
     * Sólo aplica a usuarios de una empresa: el personal de la plataforma (PlatformUser, y las
     * cuentas con rol platform_admin/support_agent) y las cuentas todavía sin empresa quedan fuera
     * — a los primeros no hay pantalla donde presentarles el aviso, y la segunda lo acepta en el
     * alta de su empresa.
     */
    public static function usuarioDebeAceptar($usuario): bool
    {
        if (!$usuario instanceof User) {
            return false;
        }

        if ($usuario->tenant_id === null) {
            return false;
        }

        if (in_array($usuario->role, ['platform_admin', 'support_agent'], true)) {
            return false;
        }

        return (bool) ($usuario->privacidad_pendiente ?? false);
    }

    /**
     * Marca una cuenta recién creada para que se le pida el aviso al entrar.
     *
     * Se llama donde nace la cuenta de una persona REAL de la empresa (alta de colaborador,
     * contratación desde el ATS, admin creado por la plataforma). Igual que `must_change_password`,
     * es una marca EXPLÍCITA: las cuentas que se fabrican en pruebas o en scripts no la llevan.
     */
    public static function marcarPendiente(User $usuario): void
    {
        $usuario->forceFill(['privacidad_pendiente' => true])->save();
    }

    /**
     * Registra la aceptación de una CUENTA y levanta el candado, en una sola operación: si sólo
     * ocurriera una de las dos, o la persona quedaría atrapada en la pantalla, o quedaría suelta
     * sin constancia (que es exactamente el defecto que este trabajo cierra).
     */
    public static function aceptarUsuario(User $usuario, string $punto, ?Request $peticion = null): PrivacyConsent
    {
        $consentimiento = self::registrar([
            'tenant_id' => $usuario->tenant_id,
            'user_id' => $usuario->id,
            'punto' => $punto,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
        ], $peticion);

        $usuario->forceFill([
            'privacidad_pendiente' => false,
            'privacidad_aceptada_version' => self::VERSION,
        ])->save();

        return $consentimiento;
    }

    /**
     * Deja constancia de que ESTA persona aceptó ESTA versión, desde aquí y en este momento.
     *
     * Idempotente por (user_id, version): volver a aceptar no duplica el registro ni reescribe la
     * fecha del primero, que es el dato que vale.
     */
    public static function registrar(array $datos, ?Request $peticion = null): PrivacyConsent
    {
        $peticion = $peticion ?: request();

        $huella = [
            'ip' => $peticion?->ip(),
            // Un user agent puede pasar de 512 caracteres; se recorta en vez de reventar el INSERT
            // (en Postgres un varchar corto lanza; en sqlite pasaría callado y mentiría después).
            'user_agent' => Str::limit((string) $peticion?->userAgent(), 500, ''),
        ];

        $llave = array_filter([
            'user_id' => $datos['user_id'] ?? null,
            'candidate_id' => $datos['candidate_id'] ?? null,
            'version' => self::VERSION,
        ], fn ($v) => $v !== null);

        // Sin titular identificable no hay a quién atribuirlo: se crea suelto (caso del alta de
        // empresa antes de que exista la cuenta) en vez de colapsar todos los anónimos en una fila.
        if (!isset($llave['user_id']) && !isset($llave['candidate_id'])) {
            return PrivacyConsent::create(array_merge($datos, $huella, ['version' => self::VERSION]));
        }

        return PrivacyConsent::firstOrCreate(
            $llave,
            array_merge($datos, $huella, ['version' => self::VERSION])
        );
    }

    /**
     * Pase de un solo uso para aceptar DESDE EL KIOSCO.
     *
     * En la tableta compartida no hay sesión de la persona: el ponche la identifica por su PIN y la
     * respuesta trae este pase. Sin él habría que aceptar el consentimiento mandando un id de
     * empleado desde el cliente — es decir, cualquiera podría firmar por cualquiera, que es
     * exactamente lo que un registro de consentimiento no puede permitir. El pase es aleatorio,
     * vive 5 minutos en caché y sólo sirve para el empleado y la empresa que lo generaron.
     */
    public static function emitirPaseDeKiosco(int $tenantId, int $userId): string
    {
        $pase = Str::random(48);

        Cache::put(self::llaveDePase($pase), ['tenant_id' => $tenantId, 'user_id' => $userId], now()->addMinutes(5));

        return $pase;
    }

    /** Canjea el pase. Devuelve null si no existe, ya se usó, caducó o es de otra empresa. */
    public static function canjearPaseDeKiosco(string $pase, int $tenantId): ?int
    {
        $datos = Cache::get(self::llaveDePase($pase));

        if (!is_array($datos) || (int) ($datos['tenant_id'] ?? 0) !== $tenantId) {
            return null;
        }

        Cache::forget(self::llaveDePase($pase));

        return (int) $datos['user_id'];
    }

    private static function llaveDePase(string $pase): string
    {
        return 'pase-privacidad-kiosco:' . hash('sha256', $pase);
    }
}
