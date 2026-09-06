<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AvisoDePrivacidad;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Vuelve a pedir el consentimiento cuando el abogado cambia el texto del aviso (2026-09-05).
 *
 * POR QUÉ EXISTE: registrar "aceptó el aviso" sin decir QUÉ VERSIÓN aceptó no sirve de nada — si
 * el texto cambia, la constancia vieja no cubre el tratamiento nuevo. Cuando se sube
 * `AvisoDePrivacidad::VERSION`, este comando vuelve a marcar a quien sólo aceptó una versión
 * anterior, y a cada quien se le presenta otra vez la pantalla de un toque al entrar.
 *
 * Como todos los comandos de esta base: SIMULACRO por defecto, escribe sólo con `--aplicar`. En
 * consola el TenantScope no aplica, así que el filtro por empresa va a mano.
 */
#[Signature('privacidad:pedir-consentimiento {--aplicar : Marcar de verdad (sin esto es un simulacro)} {--tenant= : Sólo esta empresa}')]
#[Description('Marca a quien no ha aceptado la versión VIGENTE del aviso de privacidad. Simulacro por defecto.')]
class PedirConsentimientoDePrivacidad extends Command
{
    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $query = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('tenant_id')
            ->whereNotIn('role', ['platform_admin', 'support_agent'])
            // Ya marcada = ya se le va a pedir; no hace falta volver a tocarla.
            ->where('privacidad_pendiente', false)
            ->where(function ($q) {
                $q->whereNull('privacidad_aceptada_version')
                    ->orWhere('privacidad_aceptada_version', '!=', AvisoDePrivacidad::VERSION);
            })
            ->orderBy('tenant_id')
            ->orderBy('id');

        if ($this->option('tenant')) {
            $query->where('tenant_id', (int) $this->option('tenant'));
        }

        $pendientes = $query->get(['id', 'tenant_id', 'name', 'email', 'privacidad_aceptada_version']);

        $this->newLine();
        $this->line("Versión vigente del aviso: " . AvisoDePrivacidad::VERSION);

        if ($pendientes->isEmpty()) {
            $this->info('✔ Todas las cuentas activas ya aceptaron la versión vigente (o ya están marcadas).');

            return self::SUCCESS;
        }

        $this->line($aplicar ? '── MARCANDO ──' : '── SIMULACRO (nada se marca; use --aplicar) ──');
        $this->table(
            ['Empresa', 'Cuenta', 'Correo', 'Última versión aceptada'],
            $pendientes->map(fn ($u) => [
                $u->tenant_id,
                $u->name,
                $u->email ?: '(sin correo)',
                $u->privacidad_aceptada_version ?: 'ninguna',
            ])->all()
        );

        if (!$aplicar) {
            $this->warn("Se marcarían {$pendientes->count()} cuentas. Vuelva a correrlo con --aplicar.");

            return self::SUCCESS;
        }

        User::withoutGlobalScopes()
            ->whereIn('id', $pendientes->pluck('id'))
            ->update(['privacidad_pendiente' => true]);

        $this->info("✔ {$pendientes->count()} cuentas marcadas: se les pedirá el aviso al entrar.");

        return self::SUCCESS;
    }
}
