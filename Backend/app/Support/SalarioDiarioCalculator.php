<?php

namespace App\Support;

/**
 * De lo que dice el expediente al SALARIO DIARIO, sin suponer nada (2026-08-24).
 *
 * Sustituye al `/ 6.0` que vivía dentro del motor de nómina. Ese divisor arrastraba dos errores
 * encima del otro:
 *
 *   1. Aun siendo semanal, la práctica LFT reparte entre **7**, no entre 6: el séptimo día es
 *      descanso pagado (art. 69). Repartir entre 6 y pagar 7 infla el diario un 16.67 %.
 *   2. El grande: `base_salary` **no declara de qué periodo es**, y el motor *suponía* semanal.
 *      Si el número capturado era mensual, el salario diario salía casi CINCO veces más grande —
 *      y con él el IMSS, el aguinaldo, la prima vacacional y cualquier indemnización, porque todos
 *      se calculan sobre el diario.
 *
 * Ahora el cálculo depende de lo que el expediente DECLARA, y hay tres estados, no dos:
 *
 *   · `salario_diario` capturado  → ésa es la verdad, se usa tal cual.
 *   · `base_salary` + `periodicidad_captura` declarada → divisor real (`SalarioDiario`).
 *   · `base_salary` SIN periodicidad → **PENDIENTE DE RECAPTURA**. Se conserva el resultado
 *     histórico (`/6`) para no moverle el pago a nadie en silencio, y se DECLARA que el número
 *     salió de una suposición. Ésa es la única forma honesta de ser retrocompatible: seguir
 *     pagando igual, pero dejar de fingir que se sabe.
 *
 * La migración a periodicidad declarada es por **recaptura explícita del expediente**, nunca
 * cambiando la fórmula debajo de quien ya cobra con ella. Ver
 * `TICKET_CRITICO_PERIODICIDAD_SALARIO.md`.
 */
class SalarioDiarioCalculator
{
    /** El expediente no declara sueldo alguno. */
    public const SIN_SUELDO = 'sin_sueldo';
    /** Salario diario capturado directamente. */
    public const DIARIO_DECLARADO = 'diario_declarado';
    /** Sueldo con periodicidad declarada: divisor real de la práctica LFT. */
    public const PERIODICIDAD_DECLARADA = 'periodicidad_declarada';
    /** Sueldo sin periodicidad: se conserva el `/6` histórico y se marca para recaptura. */
    public const SUPUESTO_HISTORICO = 'supuesto_historico';

    /** El divisor histórico del motor. Vive aquí, con nombre, y en ningún otro lado. */
    private const DIVISOR_HISTORICO = 6.0;

    /**
     * @return array{diario:float, base:float, origen:string, periodicidad:?string, pendiente_sueldo:bool, pendiente_periodicidad:bool}
     */
    public static function para($employee): array
    {
        $capturado = $employee->base_salary ?? $employee->salary ?? null;
        $hayCapturado = $capturado !== null && (float) $capturado > 0;

        $diarioDeclarado = $employee->salario_diario ?? null;
        if ($diarioDeclarado !== null && (float) $diarioDeclarado > 0) {
            // `base` sigue siendo el SUELDO CAPTURADO, no el diario: es lo que la pantalla muestra
            // como sueldo del colaborador y lo que la persona reconoce de su contrato. Sólo si no
            // hay monto capturado se cae al diario, para no dejar la base en cero — que es el
            // defecto que arregló `test_salario_diario_ya_no_deja_base_indefinida` y que esta
            // prueba volvió a cazar cuando lo rompí al extraer el calculador.
            return self::resultado(
                (float) $diarioDeclarado,
                $hayCapturado ? (float) $capturado : (float) $diarioDeclarado,
                self::DIARIO_DECLARADO,
                $employee->periodicidad_captura ?? 'diario'
            );
        }

        $base = $capturado;
        if (!$hayCapturado) {
            return self::resultado(0.0, 0.0, self::SIN_SUELDO, null, true);
        }

        $base = (float) $base;
        // La columna es `periodicidad_captura`, que YA EXISTE desde 2026-08-03 y guarda con qué
        // periodicidad se capturó el monto. El ticket pedía crear `salary_periodicity`; no se
        // creó a propósito: serían dos columnas para el mismo hecho, que es justo la clase de
        // duplicación que esta campaña ha estado cerrando. `salary_periodicity` sigue siendo el
        // nombre que acepta el formulario de la ficha, y ahí se traduce a esta columna.
        $periodicidad = $employee->periodicidad_captura ?? null;

        if (SalarioDiario::esValida($periodicidad)) {
            return self::resultado(
                SalarioDiario::desde($base, $periodicidad),
                $base,
                self::PERIODICIDAD_DECLARADA,
                $periodicidad
            );
        }

        // Sin periodicidad declarada: se paga EXACTAMENTE lo mismo que antes de este cambio.
        // El resultado no se corrige aquí — se marca, y se corrige cuando un humano recapture el
        // expediente. Bajarle el sueldo a alguien por un refactor no es una migración, es un
        // recorte silencioso.
        return self::resultado(
            $base / self::DIVISOR_HISTORICO,
            $base,
            self::SUPUESTO_HISTORICO,
            null,
            false,
            true
        );
    }

    /** @return array{diario:float, base:float, origen:string, periodicidad:?string, pendiente_sueldo:bool, pendiente_periodicidad:bool} */
    private static function resultado(
        float $diario,
        float $base,
        string $origen,
        ?string $periodicidad,
        bool $pendienteSueldo = false,
        bool $pendientePeriodicidad = false
    ): array {
        return [
            'diario' => $diario,
            'base' => $base,
            'origen' => $origen,
            'periodicidad' => $periodicidad,
            'pendiente_sueldo' => $pendienteSueldo,
            'pendiente_periodicidad' => $pendientePeriodicidad,
        ];
    }
}
