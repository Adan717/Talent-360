<?php

namespace App\Console\Commands;

use App\Helpers\SecurityLogger;
use App\Models\Tenant;
use App\Support\EstadoDeCobranza;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * La vía explícita de exención: marcar a una empresa como "a ésta no se le cobra".
 *
 * Una empresa exenta queda fuera del barrido de mora (suscripciones:revisar-vencidas) pase lo
 * que pase con su fecha de corte: cortesía, piloto, socio, demo interna. Es lo que evita que
 * la cobranza automática apague a alguien con quien el trato es otro.
 *
 * NINGUNA empresa nace exenta, y esta migración no marcó a las 4 que hoy existen: declarar que
 * a un cliente no se le cobra es una decisión comercial del dueño, no algo que el código deba
 * inventar por él. Las 4 están protegidas por otro camino —ninguna tiene fecha de corte, y sin
 * fecha de corte el barrido no toca a nadie— así que la exención es para cuando empiecen a
 * tenerla.
 *
 * Como todo comando que escribe en este repo: SIMULACRO por defecto, sólo actúa con --aplicar,
 * y deja rastro en saas_audit_logs.
 */
#[Signature('suscripciones:exentar
    {empresa : Id de la empresa (tenants.id)}
    {--motivo= : Por qué no se le cobra (cortesía, piloto, socio...)}
    {--quitar : Quita la exención en vez de ponerla}
    {--aplicar : Escribe el cambio (por defecto sólo simula)}')]
#[Description('Marca (o desmarca) a una empresa como exenta de cobro, fuera del barrido de mora.')]
class ExentarDeCobro extends Command
{
    public function handle(): int
    {
        $id = (int) $this->argument('empresa');
        $quitar = (bool) $this->option('quitar');
        $aplicar = (bool) $this->option('aplicar');
        $motivo = trim((string) $this->option('motivo'));

        $empresa = Tenant::find($id);
        if (!$empresa) {
            $this->error("No existe la empresa #{$id}.");

            return self::FAILURE;
        }

        if (!$quitar && $motivo === '') {
            // Una exención sin motivo es una exención que nadie va a poder explicar en seis meses.
            $this->error('Falta --motivo: hay que dejar escrito por qué a esta empresa no se le cobra.');

            return self::FAILURE;
        }

        $antes = (bool) $empresa->billing_exempt;
        $despues = !$quitar;

        if ($antes === $despues) {
            $this->info("#{$empresa->id} {$empresa->name} ya está " . ($antes ? 'exenta' : 'sujeta a cobro') . ': nada que hacer.');

            return self::SUCCESS;
        }

        $resumen = $despues
            ? "#{$empresa->id} {$empresa->name} quedaría EXENTA de cobro ({$motivo}): el barrido de mora dejará de mirarla."
            : "#{$empresa->id} {$empresa->name} volvería a estar SUJETA a cobro: el barrido de mora vuelve a mirarla.";

        $this->warn(($aplicar ? '' : 'SIMULACRO — ') . $resumen);

        if (!$aplicar) {
            $this->info('Nada se escribió. Repite con --aplicar para hacerlo efectivo.');

            return self::SUCCESS;
        }

        $empresa->billing_exempt = $despues;
        $empresa->billing_exempt_reason = $despues ? $motivo : null;
        $empresa->save();

        SecurityLogger::log(
            EstadoDeCobranza::EVENTO_EXENCION,
            $despues
                ? "Exenta de cobro: {$motivo}. Queda fuera del barrido de mora."
                : 'Se retiró la exención de cobro: vuelve a entrar al barrido de mora.',
            $empresa->id
        );

        $this->info('Listo.');

        return self::SUCCESS;
    }
}
