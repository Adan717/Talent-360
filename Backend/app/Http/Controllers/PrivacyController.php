<?php

namespace App\Http\Controllers;

use App\Models\PrivacyConsent;
use App\Models\User;
use App\Support\AvisoDePrivacidad;
use Illuminate\Http\Request;

/**
 * Consentimiento del aviso de privacidad (2026-09-05).
 *
 * El texto legal no vive aquí (es del abogado y se lee en `/privacidad`); aquí sólo se registra
 * QUIÉN aceptó QUÉ VERSIÓN, CUÁNDO y DESDE DÓNDE. La versión la estampa el servidor: nunca se
 * acepta la que mande el cliente, porque entonces cualquiera podría "firmar" una versión vieja.
 */
class PrivacyController extends Controller
{
    /**
     * Versión vigente del aviso. Pública: la consultan el portal de empleo y el kiosco, que no
     * tienen sesión, y así ninguna pantalla necesita repetir la fecha a mano.
     */
    public function version()
    {
        return response()->json([
            'version' => AvisoDePrivacidad::VERSION,
            'fecha' => AvisoDePrivacidad::FECHA_LEGIBLE,
            'ruta' => AvisoDePrivacidad::RUTA_PUBLICA,
        ]);
    }

    /** ¿La cuenta autenticada ya aceptó la versión vigente? */
    public function estado(Request $request)
    {
        $usuario = $request->user();

        $consentimiento = $usuario instanceof User
            ? PrivacyConsent::where('user_id', $usuario->id)->where('version', AvisoDePrivacidad::VERSION)->first()
            : null;

        return response()->json([
            'pendiente' => AvisoDePrivacidad::usuarioDebeAceptar($usuario),
            'version' => AvisoDePrivacidad::VERSION,
            'fecha' => AvisoDePrivacidad::FECHA_LEGIBLE,
            'aceptado_en' => $consentimiento?->created_at,
        ]);
    }

    /**
     * Aceptar desde la aplicación (pantalla obligatoria de un toque al entrar).
     */
    public function aceptar(Request $request)
    {
        $usuario = $request->user();

        if (!$usuario instanceof User) {
            return response()->json(['error' => 'Sólo una cuenta de empresa registra este consentimiento.'], 403);
        }

        $consentimiento = AvisoDePrivacidad::aceptarUsuario($usuario, AvisoDePrivacidad::PUNTO_PRIMER_INGRESO, $request);

        return response()->json([
            'success' => true,
            'version' => $consentimiento->version,
            'aceptado_en' => $consentimiento->created_at,
        ]);
    }

    /**
     * Aceptar desde el KIOSCO, con el pase de un solo uso que devolvió el ponche por PIN.
     *
     * Quien ficha en la tableta compartida no tiene sesión propia (la sesión es del encargado que
     * hospeda la tableta y sólo ancla la empresa), así que el titular se resuelve por el pase — no
     * por un id que mande el cliente, que permitiría firmar en nombre de otro.
     */
    public function aceptarDesdeKiosco(Request $request)
    {
        $request->validate(['pase' => ['required', 'string', 'max:120']]);

        $tenantId = $request->user()?->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $userId = AvisoDePrivacidad::canjearPaseDeKiosco($request->pase, (int) $tenantId);
        if ($userId === null) {
            return response()->json([
                'success' => false,
                'message' => 'El pase ya se usó o expiró. Vuelve a registrar tu asistencia.',
            ], 422);
        }

        // Se relee del padrón con el tenant explícito (en el kiosco no hay sesión de la persona que
        // resuelva el TenantScope): el pase ya acreditó la identidad, esto sólo trae sus datos.
        $titular = User::withoutGlobalScopes()->where('id', $userId)->where('tenant_id', $tenantId)->first();
        if (!$titular) {
            return response()->json(['success' => false, 'message' => 'No se pudo resolver al colaborador.'], 422);
        }

        $consentimiento = AvisoDePrivacidad::aceptarUsuario($titular, AvisoDePrivacidad::PUNTO_PRIMER_INGRESO, $request);

        return response()->json([
            'success' => true,
            'version' => $consentimiento->version,
            'aceptado_en' => $consentimiento->created_at,
        ]);
    }
}
