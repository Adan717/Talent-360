<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PendingRegistration;
use App\Mail\AbandonmentRecoveryMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ProcessAbandonedRegistrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pending:process-abandoned';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía correos de recuperación de registro inconcluso a prospectos y purga borradores expirados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando procesamiento de registros inconclusos...');

        // 1. Enviar correos de recordatorio para registros pendientes (de entre 30 min y 24 hr de antigüedad)
        $pendingRecords = PendingRegistration::where('status', 'pending')
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subMinutes(30))
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $remindedCount = 0;

        foreach ($pendingRecords as $record) {
            try {
                $payload = json_decode($record->payload, true) ?? [];
                $email = $record->admin_email ?: ($payload['admin_email'] ?? null);

                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $adminName = $payload['admin_name'] ?? 'Cliente';
                    $companyName = $payload['company_name'] ?? 'Empresa';
                    $planName = $payload['plan'] ?? 'pro';
                    $checkoutUrl = $record->checkout_url ?: env('FRONTEND_URL', 'http://localhost:5173') . '/register';

                    Mail::to($email)->send(new AbandonmentRecoveryMail(
                        $adminName,
                        $companyName,
                        $planName,
                        $checkoutUrl
                    ));

                    $record->update([
                        'reminded_at' => now(),
                        'status' => 'reminded'
                    ]);

                    $remindedCount++;
                    $this->info("Correo de recuperación enviado a: {$email}");
                }
            } catch (\Exception $e) {
                Log::error("Error enviando correo de recuperación para registro pendiente {$record->id}: " . $e->getMessage());
                $this->error("Error con {$record->id}: {$e->getMessage()}");
            }
        }

        // 2. Limpieza de registros con más de 48 horas de antigüedad que no fueron completados
        $expiredCount = PendingRegistration::whereIn('status', ['pending', 'reminded'])
            ->where('updated_at', '<=', now()->subHours(48))
            ->delete();

        $this->info("Proceso finalizado. Recordatorios enviados: {$remindedCount}. Registros expirados limpios: {$expiredCount}.");
        return 0;
    }
}
