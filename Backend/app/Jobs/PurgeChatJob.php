<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PurgeChatJob
 *
 * Job programado que elimina de forma definitiva todos los mensajes de chat
 * con más de 7 días de antigüedad para garantizar privacidad y reducción de datos.
 *
 * SPEC: "todos los mensajes de chat son temporales y el backend ejecutará una
 * limpieza automática diaria para eliminar de forma definitiva cualquier mensaje
 * con más de 7 días de antigüedad."
 *
 * Programar en app/Console/Kernel.php con: $schedule->job(new PurgeChatJob)->daily();
 */
class PurgeChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Número de reintentos si el job falla */
    public int $tries = 3;

    /** Timeout en segundos */
    public int $timeout = 60;

    /**
     * Ejecutar el job de limpieza.
     */
    public function handle(): void
    {
        $cutoffDate = Carbon::now()->subDays(7);

        try {
            // 1. Eliminar mensajes de chat de TeamChat (internal_messages) > 7 días
            $teamChatDeleted = DB::table('internal_messages')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            // 2. Eliminar mensajes del canal de chat de empleados (team_chat_messages) > 7 días
            $chatMessagesDeleted = 0;
            if (DB::getSchemaBuilder()->hasTable('team_chat_messages')) {
                $chatMessagesDeleted = DB::table('team_chat_messages')
                    ->where('created_at', '<', $cutoffDate)
                    ->delete();
            }

            $totalDeleted = $teamChatDeleted + $chatMessagesDeleted;

            Log::info('PurgeChatJob completado', [
                'cutoff_date'          => $cutoffDate->toDateTimeString(),
                'internal_messages'    => $teamChatDeleted,
                'team_chat_messages'   => $chatMessagesDeleted,
                'total_deleted'        => $totalDeleted,
                'executed_at'          => now()->toDateTimeString(),
            ]);

        } catch (\Exception $e) {
            Log::error('PurgeChatJob falló', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-lanzar para que el queue driver reintente
            throw $e;
        }
    }

    /**
     * Manejar el fallo del job tras todos los reintentos.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('PurgeChatJob falló definitivamente tras todos los reintentos', [
            'error' => $exception->getMessage(),
        ]);
    }
}
