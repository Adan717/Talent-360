<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara los cierres automáticos inválidos que escribió `shifts:close-orphans` con su regla
 * vieja (2026-08-22).
 *
 * Esa regla estampaba la salida en `max(cierre de tienda, último ponche)`. Cuando el horario
 * programado no explicaba la jornada —el error a.m./p.m. que arrastran los horarios de esta
 * empresa— la salida caía en el MISMO SEGUNDO de la entrada: jornadas de CERO minutos escritas
 * sobre asistencia real (visto en la V2: entrada 20:33:05 y salida sintética 20:33:05).
 *
 * Sólo toca filas marcadas `auto_closed` que la regla ACTUAL no habría escrito: las de duración
 * cero o negativa, y aquellas en las que la persona tuvo ponches reales después de su fin de
 * turno (su horario no explica la jornada, y hoy el barrido se niega a inventar la hora). Un
 * cierre automático correcto no se toca, y una salida puesta por una persona tampoco. Por
 * defecto SIMULA; hay que pasar --aplicar para que escriba. Nunca toca un periodo de nómina ya
 * cerrado (--desde protege el pasado si hiciera falta).
 */
#[Signature('shifts:reparar-cierres-sinteticos {--aplicar : Escribe los cambios (por defecto sólo simula)} {--desde= : No tocar nada anterior a esta fecha (Y-m-d)}')]
#[Description('Quita los cierres automáticos de cero minutos que dejó la regla vieja del barrido de turnos huérfanos.')]
class RepararCierresSinteticos extends Command
{
    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $desde = $this->option('desde');

        $q = DB::table('time_entries')
            ->where('type', 'check_out')
            ->where('details', 'like', '%auto_closed%');
        if ($desde) {
            $q->where('date', '>=', $desde);
        }
        $sinteticos = $q->orderBy('id')->get(['id', 'tenant_id', 'user_id', 'date', 'time']);

        $invalidos = [];
        foreach ($sinteticos as $fila) {
            // La entrada real de esa persona ese día (la más tardía, por si reabrió turno).
            $entrada = DB::table('time_entries')
                ->where('tenant_id', $fila->tenant_id)
                ->where('user_id', $fila->user_id)
                ->where('date', $fila->date)
                ->where('type', 'check_in')
                ->whereNull('simulation_session_id')
                ->orderByDesc('time')
                ->value('time');

            if ($entrada === null) {
                continue;
            }

            // Criterio: se retira todo cierre sintético que la regla ACTUAL no habría escrito.
            //  (a) Cero o negativo: la salida no aporta nada y ensucia la asistencia.
            //  (b) La persona tuvo ponches reales DESPUÉS de su fin de turno: su horario no
            //      explica la jornada (el error a.m./p.m. de estos horarios) y hoy el barrido se
            //      niega a inventar la hora. Un cierre de 14 segundos entra por aquí aunque no
            //      sea cero exacto.
            $finTurno = DB::table('employees')
                ->where('tenant_id', $fila->tenant_id)
                ->where('user_id', $fila->user_id)
                ->value('shiftEnd');
            $finTurno = $finTurno ? (strlen((string) $finTurno) === 5 ? $finTurno . ':00' : substr((string) $finTurno, 0, 8)) : null;

            $ultimoReal = DB::table('time_entries')
                ->where('tenant_id', $fila->tenant_id)
                ->where('user_id', $fila->user_id)
                ->where('date', $fila->date)
                ->where('id', '!=', $fila->id)
                ->whereNull('simulation_session_id')
                ->max('time');

            $ceroONegativo = substr((string) $fila->time, 0, 8) <= substr((string) $entrada, 0, 8);
            $horarioNoExplica = $finTurno !== null && $ultimoReal !== null
                && substr((string) $ultimoReal, 0, 8) > $finTurno;

            if ($ceroONegativo || $horarioNoExplica) {
                $invalidos[] = ['fila' => $fila, 'entrada' => $entrada];
            }
        }

        if (empty($invalidos)) {
            $this->info('No hay cierres automáticos inválidos.');

            return self::SUCCESS;
        }

        $this->warn(($aplicar ? 'Quitando ' : 'SIMULACRO — se quitarían ') . count($invalidos) . ' cierre(s) automático(s) inválido(s):');
        foreach ($invalidos as $x) {
            $f = $x['fila'];
            $this->line("  empresa {$f->tenant_id} · colaborador {$f->user_id} · {$f->date} · entrada {$x['entrada']} → salida sintética {$f->time}");
        }

        if (!$aplicar) {
            $this->info('Nada se escribió. Repite con --aplicar para hacerlo efectivo.');

            return self::SUCCESS;
        }

        foreach ($invalidos as $x) {
            $f = $x['fila'];
            DB::table('time_entries')->where('id', $f->id)->delete();

            // Queda constancia de que la jornada sigue SIN cerrar y la tiene que ver un humano:
            // borrar el dato falso sin dejar el aviso sería cambiar una mentira por un silencio.
            DB::table('audit_logs')->insert([
                'tenant_id' => $f->tenant_id,
                'user_id' => $f->user_id,
                'date' => $f->date,
                'type' => 'orphan_shift',
                'timestamp_str' => $f->date . ' ' . $f->time,
                'reason' => '🔴 Turno huérfano SIN cerrar: se retiró un cierre automático que el sistema no debió inventar (el horario programado no explicaba la jornada). Revísalo a mano.',
                'punishment_amount' => 0,
                'details' => json_encode(['auto_closed' => false, 'revision_manual' => true, 'reparado' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info('Listo: ' . count($invalidos) . ' cierre(s) retirado(s), cada uno con su aviso de revisión manual.');

        return self::SUCCESS;
    }
}
