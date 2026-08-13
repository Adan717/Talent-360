<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Bloque 1 (2026-08-13): marca para cambio forzado toda cuenta cuya contraseña sea una de
 * las conocidas (User::CONTRASENAS_CONOCIDAS). Se corre UNA vez por instancia tras desplegar
 * la migración. Idempotente: las ya marcadas se saltan. Reversible: la marca se quita sola
 * al cambiar la contraseña.
 */
class MarcarContrasenasConocidas extends Command
{
    protected $signature = 'usuarios:marcar-contrasenas-conocidas';

    protected $description = 'Marca must_change_password en toda cuenta cuya contraseña sea una de las conocidas (password123, 123456).';

    public function handle(): int
    {
        // Sólo se apaga el scope de tenant (el comando cruza empresas a propósito); el
        // borrado lógico se queda: una cuenta archivada no puede entrar de todos modos.
        // platform_users también entra: el admin de plataforma nacía con `123456` literal
        // (scripts_utilidad/create_admin.php) y es la cuenta más poderosa de la instancia.
        $padron = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('must_change_password', false)
            ->get(['id', 'email', 'tenant_id', 'password', 'must_change_password'])
            ->concat(\App\Models\PlatformUser::where('must_change_password', false)->get());

        $marcados = 0;
        $revisados = 0;
        foreach ($padron as $cuenta) {
            $revisados++;
            foreach (User::CONTRASENAS_CONOCIDAS as $conocida) {
                if (Hash::check($conocida, $cuenta->password)) {
                    $cuenta->must_change_password = true;
                    $cuenta->save();
                    $donde = $cuenta instanceof \App\Models\PlatformUser ? 'PLATAFORMA' : "tenant {$cuenta->tenant_id}";
                    $this->info("Marcado: {$cuenta->email} ({$donde})");
                    $marcados++;
                    break;
                }
            }
        }

        $this->info("{$marcados} cuentas marcadas de {$revisados} revisadas.");

        return self::SUCCESS;
    }
}
