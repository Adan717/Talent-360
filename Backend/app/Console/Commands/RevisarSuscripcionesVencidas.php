<?php

namespace App\Console\Commands;

use App\Helpers\SecurityLogger;
use App\Models\SaasAuditLog;
use App\Models\Tenant;
use App\Support\EstadoDeCobranza;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Barrido de cobranza: compara la fecha de corte de cada empresa y mueve el estado SOLO.
 *
 * Hasta el 2026-09-05, dejar de pagar no tenía NINGUNA consecuencia automática: la suspensión
 * era un interruptor manual del panel de plataforma y 'past_due' no lo escribía nadie.
 *
 * Qué hace y qué NO hace:
 *  · SIMULACRO por defecto. Sin --aplicar no escribe una sola fila.
 *  · Sin fecha de corte NO se suspende NUNCA. Hoy las 4 empresas vivas están así: se listan
 *    aparte y no se tocan. Sin dato no hay mora.
 *  · La empresa marcada como exenta (cortesía/piloto) no se mira, tenga lo que tenga.
 *  · Con --sin-suspender aplica sólo lo que no apaga a nadie (marcar la mora, avisar, y dejar
 *    la alerta "lista para suspender" en la bitácora). Es el modo que corre AGENDADO: el apagón
 *    deja sin reloj checador a una empresa entera y eso no se enciende solo.
 *  · Idempotente: correrlo dos veces no re-suspende lo suspendido ni duplica el aviso.
 *  · Cada cambio deja rastro en saas_audit_logs (el mismo que ya lee el panel en
 *    /platform/security-logs). El "quién" del barrido es el Sistema, y así se ve.
 *
 * El aviso NO se manda por correo: el proveedor de correo sigue bloqueado por decisión del
 * dueño (bloque 3 del plan de trabajo). Queda en la bitácora, que es lo que el backend sí
 * respalda hoy. Cuando haya proveedor, el enganche va aquí y no antes.
 *
 * Las reglas y el orden en que se evalúan viven en App\Support\EstadoDeCobranza, en un solo
 * sitio. Este comando sólo obedece y escribe.
 */
#[Signature('suscripciones:revisar-vencidas
    {--aplicar : Escribe los cambios (por defecto sólo simula)}
    {--sin-suspender : No suspende a nadie: sólo marca la mora y avisa (es el modo agendado)}
    {--dias-de-gracia= : Simula con otro periodo de gracia (por defecto, el configurado)}')]
#[Description('Marca la mora, avisa dentro del periodo de gracia y suspende a quien lo agotó.')]
class RevisarSuscripcionesVencidas extends Command
{
    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $sinSuspender = (bool) $this->option('sin-suspender');
        $graciaForzada = $this->option('dias-de-gracia');
        $diasDeGracia = ($graciaForzada !== null && $graciaForzada !== '' && (int) $graciaForzada > 0)
            ? (int) $graciaForzada
            : EstadoDeCobranza::diasDeGracia();
        $diaDeAviso = EstadoDeCobranza::diaDeAviso();
        $ahora = Carbon::now();

        $this->line("Gracia: {$diasDeGracia} dia(s) · aviso al dia {$diaDeAviso} · " .
            ($aplicar ? ($sinSuspender ? 'APLICANDO SIN SUSPENDER' : 'APLICANDO') : 'SIMULACRO'));

        // En consola el TenantScope no aplica; aquí queremos TODAS las empresas a propósito,
        // menos las borradas (SoftDeletes ya las excluye).
        $empresas = Tenant::query()->orderBy('id')->get();

        $porAccion = [];
        foreach ($empresas as $empresa) {
            $decision = EstadoDeCobranza::decidir($empresa, $ahora, $diasDeGracia, $diaDeAviso);
            $porAccion[$decision['accion']][] = ['empresa' => $empresa, 'decision' => $decision];
        }

        foreach ($porAccion as $accion => $filas) {
            $this->newLine();
            $this->line(strtoupper(str_replace('_', ' ', $accion)) . ' (' . count($filas) . ')');
            foreach ($filas as $fila) {
                $e = $fila['empresa'];
                $this->line("  #{$e->id} {$e->name} — {$fila['decision']['explicacion']}");
            }
        }

        $suspendibles = count($porAccion[EstadoDeCobranza::SUSPENDER] ?? []);
        if ($suspendibles > 0 && $sinSuspender) {
            $this->newLine();
            $this->warn("{$suspendibles} empresa(s) agotaron la gracia. Este modo NO las suspende: " .
                'queda la alerta en la bitácora y la suspensión la decide una persona.');
        }

        if (!$aplicar) {
            $this->newLine();
            $this->info('SIMULACRO: nada se escribió. Repite con --aplicar para hacerlo efectivo.');

            return self::SUCCESS;
        }

        $escritos = 0;
        foreach ($porAccion as $filas) {
            foreach ($filas as $fila) {
                $escritos += $this->aplicar($fila['empresa'], $fila['decision'], $sinSuspender, $ahora) ? 1 : 0;
            }
        }

        $this->newLine();
        $this->info("Listo: {$escritos} empresa(s) con cambios.");

        return self::SUCCESS;
    }

    /** Escribe lo que la decisión ordena. Devuelve true si tocó algo. */
    private function aplicar(Tenant $empresa, array $decision, bool $sinSuspender, Carbon $ahora): bool
    {
        $toco = false;
        $estadoPrevio = $empresa->subscription_status;
        $destino = $decision['estado_destino'];
        $dias = $decision['dias_de_mora'];
        $corte = $decision['fecha_de_corte'];

        if ($destino !== null && $destino !== $estadoPrevio) {
            $empresa->subscription_status = $destino;
            $toco = true;

            if ($destino === EstadoDeCobranza::MORA) {
                SecurityLogger::log(
                    EstadoDeCobranza::EVENTO_MORA,
                    "Mora automática: la fecha de corte ({$corte}) pasó hace {$dias} dia(s) sin pago. " .
                    "Estado {$estadoPrevio} → " . EstadoDeCobranza::MORA . '. El acceso sigue abierto.',
                    $empresa->id
                );
            } elseif ($destino === EstadoDeCobranza::ACTIVA) {
                SecurityLogger::log(
                    EstadoDeCobranza::EVENTO_REACTIVACION,
                    "Pago al corriente: la fecha de corte ({$corte}) está en el futuro. " .
                    'Estado ' . EstadoDeCobranza::MORA . ' → ' . EstadoDeCobranza::ACTIVA . '.',
                    $empresa->id
                );
            }
        }

        if ($decision['avisar']) {
            $empresa->payment_warning_sent_at = $ahora;
            $toco = true;
            SecurityLogger::log(
                EstadoDeCobranza::EVENTO_AVISO,
                "Aviso de gracia: {$dias} dia(s) sin pago desde el corte ({$corte}). " .
                'Queda en la bitácora; todavía no se manda correo porque no hay proveedor configurado.',
                $empresa->id
            );
        }

        if ($decision['suspender']) {
            if ($sinSuspender) {
                // Escalar sin apagar. Una sola alerta por ciclo de cobro: el barrido corre a
                // diario y sin esto llenaría la bitácora con la misma línea todos los días.
                if (!$this->yaEscalada($empresa->id, $corte)) {
                    SecurityLogger::log(
                        EstadoDeCobranza::EVENTO_LISTA_PARA_SUSPENDER,
                        "🔴 LISTA PARA SUSPENDER: {$dias} dia(s) de mora desde el corte ({$corte}), " .
                        'se agotó el periodo de gracia. El barrido NO la suspendió: la suspensión ' .
                        'apaga el reloj checador de toda la empresa y la decide una persona.',
                        $empresa->id
                    );
                    $toco = true;
                }
            } else {
                $empresa->is_active = false;
                $empresa->suspension_reason = EstadoDeCobranza::MOTIVO_DE_SUSPENSION;
                $empresa->suspended_at = $ahora;
                $toco = true;
                SecurityLogger::log(
                    EstadoDeCobranza::EVENTO_SUSPENSION,
                    "Suspensión automática por falta de pago: {$dias} dia(s) desde el corte ({$corte}), " .
                    'agotado el periodo de gracia.',
                    $empresa->id
                );
            }
        }

        if ($toco) {
            $empresa->save();
        }

        return $toco;
    }

    private function yaEscalada(int $tenantId, ?string $corte): bool
    {
        if ($corte === null) {
            return false;
        }

        return SaasAuditLog::where('tenant_id', $tenantId)
            ->where('event_type', EstadoDeCobranza::EVENTO_LISTA_PARA_SUSPENDER)
            ->where('created_at', '>=', $corte)
            ->exists();
    }
}
