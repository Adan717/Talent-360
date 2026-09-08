<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Lo que la APP le dice al cliente sobre su pago: el banner de "pago pendiente".
 *
 * Existe para que el aviso y el apagón NO puedan contradecirse. Quien decide si una empresa está
 * en mora, cuántos días de gracia le quedan y cuándo se apaga es `EstadoDeCobranza` —el mismo
 * motor que usa el barrido diario—; esta clase sólo traduce esa decisión a algo que una persona
 * pueda leer. Si el banner tuviera su propia cuenta de días (en el navegador, por ejemplo), la
 * pantalla acabaría prometiendo un plazo que el servidor no respeta, que es el defecto que este
 * proyecto ya pagó en Ley Silla, en el comedor y en la tolerancia del reloj.
 *
 * El banner NUNCA bloquea: avisa. Lo que apaga el reloj checador es `CheckTenantActive` mirando
 * `tenants.is_active`, y eso sólo lo escribe el barrido cuando la gracia ya se agotó.
 *
 * Devuelve null cuando no hay nada que decir (al corriente, exenta, sin fecha de corte, o
 * suspendida por una razón que no es el pago): un banner que sale sin motivo se vuelve invisible.
 */
final class AvisoDeCobranza
{
    /** Ámbar: hay deuda pero la empresa sigue trabajando. */
    public const TONO_AVISO = 'aviso';

    /** Rojo: se agotó la gracia (o el reloj ya está apagado por falta de pago). */
    public const TONO_APAGON = 'apagon';

    /**
     * @return array{tono:string, titulo:string, mensaje:string, dias_restantes:?int,
     *               fecha_limite:?string, fecha_de_corte:?string, bloquea:bool}|null
     */
    public static function para($tenant, ?CarbonInterface $ahora = null): ?array
    {
        if (!$tenant) {
            return null;
        }

        $ahora = $ahora ?: Carbon::now();
        $decision = EstadoDeCobranza::decidir($tenant, $ahora);
        $enMoraDeclarada = (string) ($tenant->subscription_status ?? '') === EstadoDeCobranza::MORA;

        // Ya apagada. Sólo se habla de pago si la empresa está marcada en mora: una suspensión
        // manual por otra razón no es asunto de cobranza y decir lo contrario sería mentir.
        if ($decision['accion'] === EstadoDeCobranza::YA_SUSPENDIDA) {
            if (!$enMoraDeclarada) {
                return null;
            }

            return self::aviso(
                self::TONO_APAGON,
                'Registro de asistencia suspendido por falta de pago',
                'Tu empresa no puede registrar asistencia hasta que se regularice el pago. '
                . 'En cuanto el pago se procese, el acceso se restablece.',
                null,
                null,
                $decision['fecha_de_corte'] ?? null
            );
        }

        if (!in_array($decision['accion'], [
            EstadoDeCobranza::EN_GRACIA,
            EstadoDeCobranza::AVISAR,
            EstadoDeCobranza::SUSPENDER,
        ], true)) {
            return null;
        }

        $diasDeGracia = EstadoDeCobranza::diasDeGracia();
        $diasDeMora = (int) ($decision['dias_de_mora'] ?? 0);
        // El corte es el último día cubierto; la gracia se agota `diasDeGracia` días después.
        $limite = Carbon::parse($decision['fecha_de_corte'])->addDays($diasDeGracia);

        if ($decision['accion'] === EstadoDeCobranza::SUSPENDER) {
            return self::aviso(
                self::TONO_APAGON,
                'Se agotó el periodo de gracia',
                "Pasaron {$diasDeMora} días desde tu fecha de corte y los {$diasDeGracia} días de "
                . 'gracia terminaron. El registro de asistencia se suspenderá en la siguiente '
                . 'revisión diaria si no se recibe el pago.',
                0,
                $limite->toDateString(),
                $decision['fecha_de_corte']
            );
        }

        $restantes = max(0, $diasDeGracia - $diasDeMora);

        return self::aviso(
            self::TONO_AVISO,
            'Tenemos un pago pendiente',
            'No hemos podido procesar el cobro de tu suscripción. Tienes '
            . ($restantes === 1 ? '1 día' : "{$restantes} días")
            . ' (hasta el ' . $limite->translatedFormat('j \d\e F') . ') para regularizarlo. '
            . 'Después de esa fecha, tu empresa dejará de registrar asistencia.',
            $restantes,
            $limite->toDateString(),
            $decision['fecha_de_corte']
        );
    }

    private static function aviso(
        string $tono,
        string $titulo,
        string $mensaje,
        ?int $diasRestantes,
        ?string $fechaLimite,
        ?string $fechaDeCorte
    ): array {
        return [
            'tono' => $tono,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'dias_restantes' => $diasRestantes,
            'fecha_limite' => $fechaLimite,
            'fecha_de_corte' => $fechaDeCorte,
            // El aviso no cierra ninguna puerta. Quien apaga es CheckTenantActive con is_active.
            'bloquea' => false,
        ];
    }
}
