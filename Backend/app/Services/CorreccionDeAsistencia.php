<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use App\Scopes\ExcludeAnuladasScope;
use App\Support\BitacoraDeAsistencia;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corregir un fichaje: se ANULA y se sustituye, nunca se sobrescribe (2026-08-25).
 *
 * Es la regla de la póliza contable llevada al código. El fichaje equivocado se queda donde está,
 * marcado, y otro lo reemplaza. Los dos se conservan: para la nómina y los reportes sólo cuenta el
 * nuevo; para quien tenga que probar qué pasó, ahí están los dos y el motivo por el que se cambió.
 *
 * Tres cosas que este servicio garantiza y que la pantalla no puede saltarse porque viven aquí:
 *
 *   1. **Motivo obligatorio.** Sin razón escrita no hay corrección. Una corrección que no se puede
 *      explicar no se puede defender.
 *   2. **Rastro firmado.** Todo el trabajo va dentro de `BitacoraDeAsistencia::firmando()`, así que
 *      lo que el trigger escriba en el historial lleva el nombre de quien la autorizó y el número
 *      de la corrección — no un cambio anónimo.
 *   3. **Aviso al colaborador.** Decisión del dueño (2026-08-24) y es obligatoria: un ajuste de
 *      asistencia que la persona nunca supo que ocurrió es lo que se ve mal en un juicio. El aviso
 *      se le manda a su reloj por el mismo canal que ya usa el jefe para escribirle.
 *
 * Quién puede llamarlo: sólo quien tenga `manage_punch_corrections`, la capacidad aislada. No viene
 * incluida en `admin` por ser admin — mover la evidencia con la que la empresa se defiende se
 * otorga a quien responde por ella.
 */
class CorreccionDeAsistencia
{
    public const TIPO_ALTA = 'alta';
    public const TIPO_ANULACION = 'anulacion';
    public const TIPO_SUSTITUCION = 'sustitucion';

    /**
     * Anula un fichaje y —si se indican datos nuevos— inserta el que lo sustituye.
     *
     * @param  array<string,mixed>  $datosNuevos  Campos del fichaje que reemplaza (p. ej. ['time' => '09:00:00']).
     *                                            Vacío = sólo se anula (un duplicado, un fichaje que no debió existir).
     * @return array{correccion_id:int, anulado_id:int, nuevo_id:?int}
     */
    public function corregir(TimeEntry $original, array $datosNuevos, string $motivo, User $autoriza): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new RuntimeException('Una corrección de asistencia necesita un motivo escrito: sin él no se puede defender.');
        }

        if ($original->anulado_at !== null) {
            throw new RuntimeException('Ese fichaje ya está anulado. Corrige el que lo sustituyó, no éste.');
        }

        $antes = $original->getAttributes();

        return BitacoraDeAsistencia::firmando(
            $autoriza->id,
            'correccion_de_asistencia',
            null,
            function () use ($original, $datosNuevos, $motivo, $autoriza, $antes) {
                // 1. La corrección primero: su id firma el resto del trabajo en el historial.
                $correccionId = DB::table('asistencia_correcciones')->insertGetId([
                    'tenant_id' => $original->tenant_id,
                    'time_entry_id' => $original->id,
                    'nueva_time_entry_id' => null,
                    'tipo' => empty($datosNuevos) ? self::TIPO_ANULACION : self::TIPO_SUSTITUCION,
                    'valor_anterior' => json_encode($antes),
                    'valor_nuevo' => empty($datosNuevos) ? null : json_encode($datosNuevos),
                    'motivo' => $motivo,
                    'autorizado_por' => $autoriza->id,
                    'empleado_user_id' => $original->user_id,
                    'notificado_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // A partir de aquí, cada cambio queda ligado a ESTA corrección en el historial.
                BitacoraDeAsistencia::declarar($autoriza->id, 'correccion_de_asistencia', $correccionId);

                // 2. Se anula el original. No se borra: es la evidencia.
                DB::table('time_entries')->where('id', $original->id)->update([
                    'anulado_at' => now(),
                    'anulado_por_correccion_id' => $correccionId,
                    'updated_at' => now(),
                ]);

                // 3. El sustituto, si lo hay. Hereda lo que no se corrige del original, para que
                //    una corrección de la hora no pierda la foto, el puesto ni la tolerancia
                //    aplicada — todo eso también es evidencia.
                $nuevoId = null;
                if (!empty($datosNuevos)) {
                    $nuevo = collect($antes)
                        ->except(['id', 'created_at', 'updated_at', 'anulado_at', 'anulado_por_correccion_id'])
                        ->merge($datosNuevos)
                        ->merge([
                            // Nace marcado: así la etiqueta "⚠️ Corregido" sale sola en toda
                            // pantalla que ya lea el fichaje, sin cruzar tablas ni acordarse.
                            'creado_por_correccion_id' => $correccionId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])
                        ->all();

                    $nuevoId = DB::table('time_entries')->insertGetId($nuevo);

                    DB::table('asistencia_correcciones')
                        ->where('id', $correccionId)
                        ->update(['nueva_time_entry_id' => $nuevoId, 'updated_at' => now()]);
                }

                // 4. El aviso al colaborador. Obligatorio, y por eso va dentro de la misma
                //    transacción: si el aviso no se puede escribir, la corrección no ocurre.
                $this->avisarAlColaborador($original, $motivo, $autoriza, $correccionId);
                $this->sellarAviso($correccionId);

                return [
                    'correccion_id' => $correccionId,
                    'anulado_id' => $original->id,
                    'nuevo_id' => $nuevoId,
                ];
            }
        );
    }

    /** Da de alta un fichaje que faltaba (un olvido), con su motivo. No anula nada. */
    public function darDeAlta(array $datos, string $motivo, User $autoriza): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new RuntimeException('Dar de alta un fichaje a mano necesita un motivo escrito.');
        }

        return BitacoraDeAsistencia::firmando(
            $autoriza->id,
            'alta_manual_de_fichaje',
            null,
            function () use ($datos, $motivo, $autoriza) {
                $correccionId = DB::table('asistencia_correcciones')->insertGetId([
                    'tenant_id' => $datos['tenant_id'],
                    'time_entry_id' => null,
                    'nueva_time_entry_id' => null,
                    'tipo' => self::TIPO_ALTA,
                    'valor_anterior' => null,
                    'valor_nuevo' => json_encode($datos),
                    'motivo' => $motivo,
                    'autorizado_por' => $autoriza->id,
                    'empleado_user_id' => $datos['user_id'],
                    'notificado_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                BitacoraDeAsistencia::declarar($autoriza->id, 'alta_manual_de_fichaje', $correccionId);

                $nuevoId = DB::table('time_entries')->insertGetId(array_merge($datos, [
                    'creado_por_correccion_id' => $correccionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                DB::table('asistencia_correcciones')
                    ->where('id', $correccionId)
                    ->update(['nueva_time_entry_id' => $nuevoId, 'updated_at' => now()]);

                $this->avisarDeAltaManual($datos, $motivo, $autoriza, $correccionId);
                $this->sellarAviso($correccionId);

                return ['correccion_id' => $correccionId, 'anulado_id' => null, 'nuevo_id' => $nuevoId];
            }
        );
    }

    /**
     * Historia completa de un fichaje: el vigente y todos los anulados que lo precedieron. Es lo
     * que se enseña en una auditoría — por eso mira TAMBIÉN los anulados, a propósito.
     */
    public function historiaDelDia(int $tenantId, int $userId, string $fecha)
    {
        return TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('date', $fecha)
            ->orderBy('id')
            ->get();
    }

    private function avisarAlColaborador(TimeEntry $original, string $motivo, User $autoriza, int $correccionId): void
    {
        $hora = substr((string) $original->time, 0, 5);

        $this->mensajePrivado(
            $original->tenant_id,
            $autoriza->id,
            $original->user_id,
            "Se corrigió un registro de tu asistencia del {$original->date} (marcaba {$hora}). "
                . "Motivo: {$motivo}. Autorizó: {$autoriza->name}. Folio de corrección #{$correccionId}. "
                . 'Si no estás de acuerdo, dilo a tu jefe directo.'
        );
    }

    private function avisarDeAltaManual(array $datos, string $motivo, User $autoriza, int $correccionId): void
    {
        $hora = substr((string) ($datos['time'] ?? ''), 0, 5);

        $this->mensajePrivado(
            (int) $datos['tenant_id'],
            $autoriza->id,
            (int) $datos['user_id'],
            "Se agregó a mano un registro de tu asistencia del {$datos['date']} ({$hora}). "
                . "Motivo: {$motivo}. Autorizó: {$autoriza->name}. Folio de corrección #{$correccionId}."
        );
    }

    /**
     * Deja constancia de que el aviso salió.
     *
     * (2026-08-25) Se detectó al corregir un fichaje real en produccion: el mensaje llegaba al
     * reloj del colaborador pero `notificado_at` se quedaba nulo, o sea que el expediente de la
     * correccion decia "sin avisar" mientras el aviso ya estaba entregado. Un registro que afirma
     * algo que no corresponde con lo que el sistema hizo es justo lo que esta bitacora existe para
     * evitar — y aqui lo estaba haciendo la bitacora misma.
     */
    private function sellarAviso(int $correccionId): void
    {
        DB::table('asistencia_correcciones')
            ->where('id', $correccionId)
            ->update(['notificado_at' => now(), 'updated_at' => now()]);
    }

    /** Mismo canal por el que el jefe ya le escribe: le llega a su reloj. */
    private function mensajePrivado(int $tenantId, int $de, int $para, string $texto): void
    {
        DB::table('internal_messages')->insert([
            'tenant_id' => $tenantId,
            'sender_id' => $de,
            'receiver_id' => $para,
            'type' => 'private',
            'content' => $texto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
