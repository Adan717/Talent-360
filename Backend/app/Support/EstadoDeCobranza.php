<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad de la cobranza: qué estados existen, cuándo se pasa de uno a otro y
 * —sobre todo— a quién NO se toca nunca.
 *
 * ANTES (verificado el 2026-09-05): pasar de "no pagó" a "suspendida" era 100% manual
 * (PlatformAdminController::toggleTenantStatus, el interruptor del panel de plataforma) y el
 * estado 'past_due' NO lo escribía ningún código del backend: sólo existía como comentario en
 * la migración y como color ámbar en la pantalla. Un cliente podía dejar de pagar para siempre
 * sin que el sistema se enterara.
 *
 * Los cuatro estados de tenants.subscription_status:
 *   trial     — periodo de prueba.
 *   active    — al corriente (lo escriben los webhooks de Stripe / Mercado Pago al cobrar).
 *   past_due  — mora: pasó la fecha de corte y no hay pago. Lo escribe el barrido.
 *   cancelled — baja. La escribe el interruptor manual del panel.
 *
 * El apagón (dejar a la empresa sin acceso) NO lo decide esta columna: lo decide
 * tenants.is_active, que es lo que mira CheckTenantActive. Por eso la suspensión automática
 * deja 'past_due' y no 'cancelled': la deuda sigue viva, no es una baja.
 *
 * Transiciones, en el orden EXACTO en que se evalúan (el orden es la seguridad):
 *   1. inquilino principal de la plataforma  → INTOCABLE      (nunca, jamás)
 *   2. billing_exempt = true                 → EXENTA         (cortesía/piloto: no se cobra)
 *   3. is_active = false                     → YA_SUSPENDIDA  (ni se re-suspende ni se reactiva)
 *   4. current_period_end vacío              → SIN_FECHA_DE_CORTE (sin dato no hay mora)
 *   5. hoy <= fecha de corte                 → AL_CORRIENTE   (y si venía en mora, vuelve a active)
 *   6. días de mora >  días de gracia        → SUSPENDER
 *   7. días de mora >= día de aviso          → AVISAR         (una sola vez por ciclo)
 *   8. resto                                 → EN_GRACIA
 *
 * El periodo de gracia por defecto es 5 días porque son los que el contrato ya le promete al
 * cliente en los Términos y Condiciones ("Talent360 otorgará un periodo de gracia de 5 días
 * naturales"). Si el código usara otro número, la pantalla estaría afirmando algo que el
 * backend no respalda. Es configurable en system_settings global por si el dueño lo cambia,
 * pero el default vive aquí y en el contrato, no en dos lados distintos.
 *
 * La cuenta se hace en la zona horaria de la aplicación, no en la del tenant: la fecha de corte
 * es un hecho de calendario del cobro (la escribe la pasarela de pago), no un turno de trabajo.
 * No se usa TenantTimezone a propósito.
 */
class EstadoDeCobranza
{
    /** Estados de tenants.subscription_status. */
    public const PRUEBA = 'trial';
    public const ACTIVA = 'active';
    public const MORA = 'past_due';
    public const CANCELADA = 'cancelled';

    /** Días naturales de gracia tras la fecha de corte, y día en que se avisa. */
    public const DIAS_DE_GRACIA_POR_DEFECTO = 5;
    public const DIA_DE_AVISO_POR_DEFECTO = 3;

    /** Llaves en system_settings (tenant_id NULL = ajuste global de la plataforma). */
    public const LLAVE_DIAS_DE_GRACIA = 'billing_grace_days';
    public const LLAVE_DIA_DE_AVISO = 'billing_warning_day';

    /** Decisiones que puede tomar el barrido. */
    public const INTOCABLE = 'intocable';
    public const EXENTA = 'exenta';
    public const YA_SUSPENDIDA = 'ya_suspendida';
    public const SIN_FECHA_DE_CORTE = 'sin_fecha_de_corte';
    public const AL_CORRIENTE = 'al_corriente';
    public const EN_GRACIA = 'en_gracia';
    public const AVISAR = 'avisar';
    public const SUSPENDER = 'suspender';

    /** Eventos que quedan en saas_audit_logs (quién/cuándo/por qué). */
    public const EVENTO_MORA = 'cobranza_mora_marcada';
    public const EVENTO_AVISO = 'cobranza_aviso_de_gracia';
    public const EVENTO_LISTA_PARA_SUSPENDER = 'cobranza_lista_para_suspender';
    public const EVENTO_SUSPENSION = 'cobranza_suspension_automatica';
    public const EVENTO_REACTIVACION = 'cobranza_pago_al_corriente';
    public const EVENTO_EXENCION = 'cobranza_exencion';
    public const EVENTO_INTERRUPTOR_MANUAL = 'cobranza_interruptor_manual';

    public const MOTIVO_DE_SUSPENSION = 'Falta de pago';

    public static function diasDeGracia(): int
    {
        return self::ajusteGlobal(self::LLAVE_DIAS_DE_GRACIA, self::DIAS_DE_GRACIA_POR_DEFECTO);
    }

    public static function diaDeAviso(): int
    {
        return self::ajusteGlobal(self::LLAVE_DIA_DE_AVISO, self::DIA_DE_AVISO_POR_DEFECTO);
    }

    /**
     * El inquilino de la propia plataforma nunca se suspende: dejarlo fuera apagaría el panel
     * desde el que se administra todo lo demás. Mismo criterio que ya aplica el interruptor
     * manual en PlatformAdminController::toggleTenantStatus.
     */
    public static function esInquilinoPrincipal($tenant): bool
    {
        return (int) ($tenant->id ?? 0) === 1 || ($tenant->subdomain ?? null) === 'talent360';
    }

    /**
     * Decide qué hacer con UNA empresa. No escribe nada: sólo dice qué correspondería.
     *
     * @return array{accion:string, dias_de_mora:?int, fecha_de_corte:?string,
     *               estado_destino:?string, suspender:bool, avisar:bool, explicacion:string}
     */
    public static function decidir(
        $tenant,
        CarbonInterface $ahora,
        ?int $diasDeGracia = null,
        ?int $diaDeAviso = null
    ): array {
        $diasDeGracia = $diasDeGracia ?? self::diasDeGracia();
        $diaDeAviso = $diaDeAviso ?? self::diaDeAviso();
        $estadoActual = $tenant->subscription_status ?? null;

        if (self::esInquilinoPrincipal($tenant)) {
            return self::decision(self::INTOCABLE, 'Inquilino principal de la plataforma: no se toca nunca.');
        }

        if ((bool) ($tenant->billing_exempt ?? false)) {
            $motivo = trim((string) ($tenant->billing_exempt_reason ?? '')) ?: 'sin motivo anotado';

            return self::decision(self::EXENTA, "Exenta de cobro ({$motivo}): el barrido no la mira.");
        }

        if (!(bool) ($tenant->is_active ?? true)) {
            return self::decision(self::YA_SUSPENDIDA, 'Ya está suspendida: no se re-suspende ni se reactiva sola.');
        }

        $corteCrudo = $tenant->current_period_end ?? null;
        if (empty($corteCrudo)) {
            // EL CANDADO. Hoy las 4 empresas vivas están así. Sin fecha de corte no hay forma de
            // saber si alguien debe: se lista y no se toca.
            return self::decision(self::SIN_FECHA_DE_CORTE, 'Sin fecha de corte: no hay mora que calcular, no se toca.');
        }

        try {
            $corte = Carbon::parse($corteCrudo);
        } catch (\Throwable $e) {
            // Fecha ilegible = dato en el que no se puede confiar. Se trata como si no hubiera.
            return self::decision(self::SIN_FECHA_DE_CORTE, 'Fecha de corte ilegible: no se toca.');
        }

        $fecha = $corte->format('Y-m-d');

        if ($ahora->lessThanOrEqualTo($corte)) {
            // Al corriente. Si venía marcada en mora (pagó tarde y la pasarela movió el corte),
            // el estado vuelve a 'active'. Nunca reactiva el acceso: eso lo decide is_active.
            return self::decision(
                self::AL_CORRIENTE,
                'Al corriente: la fecha de corte todavía no llega.',
                null,
                $fecha,
                $estadoActual === self::MORA ? self::ACTIVA : null
            );
        }

        // Días COMPLETOS transcurridos desde la fecha de corte. abs() porque el sentido del diff
        // cambió entre Carbon 2 y 3; aquí ya sabemos que $ahora es posterior al corte.
        $dias = (int) floor(abs($corte->diffInSeconds($ahora)) / 86400);

        if ($dias > $diasDeGracia) {
            return self::decision(
                self::SUSPENDER,
                "Mora de {$dias} dia(s): se agotaron los {$diasDeGracia} de gracia.",
                $dias,
                $fecha,
                self::MORA,
                true
            );
        }

        if ($dias >= $diaDeAviso && !self::yaAvisadaEnEsteCiclo($tenant, $corte)) {
            return self::decision(
                self::AVISAR,
                "Mora de {$dias} dia(s): toca avisar (dia {$diaDeAviso} de {$diasDeGracia} de gracia).",
                $dias,
                $fecha,
                self::MORA,
                false,
                true
            );
        }

        return self::decision(
            self::EN_GRACIA,
            "Mora de {$dias} dia(s): dentro de los {$diasDeGracia} de gracia.",
            $dias,
            $fecha,
            self::MORA
        );
    }

    /**
     * ¿Ya se avisó en ESTE ciclo? La marca se compara contra la fecha de corte vigente, así que
     * en cuanto la empresa paga y el corte avanza, la marca vieja deja de contar sola.
     */
    public static function yaAvisadaEnEsteCiclo($tenant, CarbonInterface $corte): bool
    {
        $marca = $tenant->payment_warning_sent_at ?? null;
        if (empty($marca)) {
            return false;
        }

        try {
            return Carbon::parse($marca)->greaterThanOrEqualTo($corte);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function decision(
        string $accion,
        string $explicacion,
        ?int $dias = null,
        ?string $fecha = null,
        ?string $estadoDestino = null,
        bool $suspender = false,
        bool $avisar = false
    ): array {
        return [
            'accion' => $accion,
            'dias_de_mora' => $dias,
            'fecha_de_corte' => $fecha,
            'estado_destino' => $estadoDestino,
            'suspender' => $suspender,
            'avisar' => $avisar,
            'explicacion' => $explicacion,
        ];
    }

    private static function ajusteGlobal(string $llave, int $default): int
    {
        $fila = DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', $llave)
            ->first();

        if (!$fila) {
            return $default;
        }

        // El valor puede venir json-encoded ("5") o crudo (5), como el resto de system_settings.
        $decodificado = json_decode((string) $fila->value, true);
        $valor = is_numeric($decodificado) ? (int) $decodificado : (int) trim((string) $fila->value, '"');

        return $valor > 0 ? $valor : $default;
    }
}
