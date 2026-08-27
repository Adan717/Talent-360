<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retira de este servidor los sellos digitales (CSD) que ya no se van a usar (2026-08-26).
 *
 * El CSD es el sello con el que se firma ante el SAT a nombre de una empresa: el equivalente
 * digital de su firma. Con el timbrado CFDI apagado, tener uno guardado es custodiar la firma
 * fiscal de un cliente **para no utilizarla nunca** — puro riesgo sin beneficio.
 *
 * ÉSTE ES EL ÚNICO COMANDO DE ESTA CAMPAÑA QUE BORRA DE VERDAD, y es a propósito: con evidencia
 * de asistencia la regla es anular y conservar, porque el valor está en poder probar qué pasó.
 * Aquí es al revés — el valor está en NO tenerlo. Un certificado que no existe no se puede filtrar.
 *
 * Aun así, no se borra a ciegas:
 *  · **Simulacro por defecto**: sin `--aplicar` sólo enseña qué empresas tienen sello.
 *  · **Se dice qué se va a retirar**, empresa por empresa, antes de tocar nada.
 *  · **No se toca nada más**: sólo las tres columnas del sello. El RFC, la razón social y el
 *    domicilio fiscal se quedan — son datos de la empresa, no su firma.
 */
#[Signature('billing:purgar-sellos {--aplicar : Retirar los sellos (sin esto es un simulacro)} {--tenant= : Sólo esta empresa}')]
#[Description('Retira los sellos digitales (CSD) guardados en la base. Simulacro por defecto.')]
class PurgarSellosFiscales extends Command
{
    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $query = Tenant::query()
            ->where(function ($q) {
                $q->whereNotNull('csd_certificate')
                    ->orWhereNotNull('csd_private_key')
                    ->orWhereNotNull('csd_password');
            })
            ->orderBy('id');

        if ($this->option('tenant')) {
            $query->where('id', (int) $this->option('tenant'));
        }

        $conSello = $query->get();

        if ($conSello->isEmpty()) {
            $this->info('✔ Ninguna empresa tiene sellos digitales guardados en este servidor.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($aplicar ? '── RETIRANDO SELLOS ──' : '── SIMULACRO (nada se borra; use --aplicar) ──');

        $this->table(
            ['Empresa', 'Certificado', 'Llave privada', 'Contraseña'],
            $conSello->map(fn ($t) => [
                $t->id . ' · ' . $t->name,
                // Nunca se imprime el contenido: es material criptográfico.
                $t->csd_certificate ? 'sí' : '—',
                $t->csd_private_key ? 'sí' : '—',
                $t->csd_password ? 'sí' : '—',
            ])->all()
        );

        if (!$aplicar) {
            $this->warn($conSello->count() . ' empresa(s) quedarían sin sello. Nada se borró.');

            return self::SUCCESS;
        }

        foreach ($conSello as $tenant) {
            // Se escribe por consulta directa para no disparar mutadores ni eventos del modelo:
            // aquí sólo se quiere que esas tres columnas dejen de contener nada.
            DB::table('tenants')->where('id', $tenant->id)->update([
                'csd_certificate' => null,
                'csd_private_key' => null,
                'csd_password' => null,
                'updated_at' => now(),
            ]);

            $this->line('   · ' . $tenant->name . ': sello retirado.');
        }

        $this->newLine();
        $this->info($conSello->count() . ' sello(s) retirado(s) de este servidor.');
        $this->line('El RFC, la razón social y el domicilio fiscal NO se tocaron: son datos de la');
        $this->line('empresa, no su firma.');
        $this->newLine();
        $this->warn('Recuérdale al cliente que, si su sello estuvo aquí, puede revocarlo y generar');
        $this->warn('uno nuevo desde el portal del SAT. Es gratis y es la única forma de estar');
        $this->warn('seguro de que una copia que ya salió de sus manos deja de servir.');

        return self::SUCCESS;
    }
}
