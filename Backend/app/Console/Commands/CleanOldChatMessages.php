<?php

namespace App\Console\Commands;

use App\Support\RetencionChat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('chat:clean-old-messages')]
#[Description('Purga el chat de EQUIPO segun la retencion configurada por empresa (7 dias por defecto, tope 30). Respeta mensajes conservados, privados y megafono.')]
class CleanOldChatMessages extends Command
{
    /**
     * Bloque 2 / D3 (2026-08-13). Antes esto borraba `team_chat_messages` a los 7 días fijos —
     * una tabla que NADA lee ni escribe: la purga era teatro y el chat real (`internal_messages`)
     * no se purgaba jamás. Ahora purga el chat de EQUIPO real, por empresa:
     *
     *  - Sólo mensajes de canal (receiver_id NULL): los PRIVADOS y el MEGÁFONO (announcement)
     *    esperan la decisión (c) de D3 — no se tocan. Un `type` NULL tampoco se toca (en SQL,
     *    NULL != 'announcement' no es verdadero, y ésa es la dirección segura).
     *  - Un mensaje con `preserved_at` (citado en un incidente) se conserva indefinidamente.
     */
    public function handle()
    {
        $tenants = DB::table('internal_messages')->distinct()->pluck('tenant_id');

        $total = 0;
        foreach ($tenants as $tenantId) {
            $dias = RetencionChat::dias((int) $tenantId);
            $borrados = DB::table('internal_messages')
                ->where('tenant_id', $tenantId)
                ->whereNull('receiver_id')
                ->where('type', '!=', 'announcement')
                ->whereNull('preserved_at')
                ->where('created_at', '<', now()->subDays($dias))
                ->delete();
            if ($borrados > 0) {
                $this->info("Tenant {$tenantId}: {$borrados} mensajes de equipo purgados (retención {$dias} días).");
            }
            $total += $borrados;
        }

        // Bloque 6: la bitácora del asistente de reportes (frase + quién) lleva datos
        // personales y HEREDA esta misma retención por empresa — decisión del plan.
        $frases = 0;
        foreach (DB::table('report_intent_logs')->distinct()->pluck('tenant_id') as $tenantId) {
            $frases += DB::table('report_intent_logs')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', now()->subDays(RetencionChat::dias((int) $tenantId)))
                ->delete();
        }

        // La tabla muerta se sigue drenando para que no acumule datos personales sin dueño.
        $muertos = DB::table('team_chat_messages')
            ->where('created_at', '<', now()->subDays(RetencionChat::DIAS_POR_DEFECTO))
            ->delete();

        $this->info("Total: {$total} mensajes de equipo purgados; {$frases} frases del asistente; {$muertos} de la tabla legada team_chat_messages.");
    }
}
