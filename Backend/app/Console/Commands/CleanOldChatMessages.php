<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('chat:clean-old-messages')]
#[Description('Elimina los mensajes del chat interno de equipo con mas de 7 dias de antiguedad.')]
class CleanOldChatMessages extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deleted = DB::table('team_chat_messages')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Se eliminaron {$deleted} mensajes antiguos del chat de equipo.");
    }
}
