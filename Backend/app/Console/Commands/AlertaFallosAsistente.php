<?php

namespace App\Console\Commands;

use App\Helpers\SecurityLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('reportes:alerta-fallos-asistente')]
#[Description('Alerta si el asistente de reportes esta fallando demasiado (>=30% en 7 dias, minimo 10 intentos).')]
class AlertaFallosAsistente extends Command
{
    /**
     * Bloque 6: "la pieza que evita repetir esta conversación en marzo". Si el proveedor
     * degrada o retira el modelo, esto lo dice en la bitácora del Monitor (audit_logs, vía
     * SecurityLogger) en vez de esperar a que un cliente se queje. No manda correo: hoy no
     * sale ningún correo de la instancia (bloque 3 pendiente).
     */
    public function handle(): int
    {
        $porTenant = DB::table('report_intent_logs')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('tenant_id, count(*) as total, sum(case when exito then 0 else 1 end) as fallos')
            ->groupBy('tenant_id')
            // havingRaw: Postgres NO acepta el alias del SELECT en HAVING (sqlite sí — la
            // familia exacta de suite-postgres-vs-sqlite; lo cazó la ronda adversarial).
            ->havingRaw('count(*) >= ?', [10])
            ->get();

        $alertados = 0;
        foreach ($porTenant as $fila) {
            $tasa = $fila->fallos / max(1, $fila->total);
            if ($tasa >= 0.30) {
                SecurityLogger::log(
                    'asistente_reportes_fallando',
                    sprintf(
                        'El asistente de reportes falló %d de %d veces en los últimos 7 días (%d%%). Revisar la llave/modelo de OpenAI.',
                        $fila->fallos, $fila->total, (int) round($tasa * 100)
                    ),
                    (int) $fila->tenant_id
                );
                $alertados++;
            }
        }

        $this->info("{$alertados} empresas alertadas de " . count($porTenant) . ' con uso suficiente.');

        return self::SUCCESS;
    }
}
