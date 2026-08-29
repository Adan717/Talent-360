<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\User;
use App\Support\HorarioDeLaEmpresa;
use App\Support\SalarioDiario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Importación masiva de plantilla desde un archivo CSV (2026-08-28).
 *
 * Sin esto, dar de alta a un cliente de cuarenta personas es capturarlas una por una — el trabajo
 * recae en quien vende, y es la diferencia entre cerrar una cuenta en una tarde o en una semana.
 *
 * TRES DECISIONES QUE MANDAN AQUÍ:
 *
 *  1. **Simulacro por defecto.** `revisar()` no escribe NADA: devuelve, renglón por renglón, qué
 *     se daría de alta y qué está mal. Sólo `importar()` escribe. Es el mismo criterio de los
 *     comandos del proyecto (`--aplicar`): sobre datos de personas, primero se ve y luego se hace.
 *
 *  2. **Todo o nada.** Si UN renglón está mal, no se escribe ninguno. Media plantilla importada
 *     es peor que ninguna: nadie sabe quién quedó dentro, y reintentar duplica. Se devuelve la
 *     lista completa de errores con su número de renglón para corregir el archivo y repetir.
 *
 *  3. **Las mismas reglas del alta de uno.** No hay una segunda puerta con criterios propios:
 *     el correo es OPCIONAL y JAMÁS se inventa (una plantilla de piso entra por kiosco con su
 *     PIN); la contraseña nace aleatoria de 32 caracteres que nadie conoce; el puesto tiene que
 *     existir EN ESA empresa; la fecha de ingreso es obligatoria porque de ella cuelgan la
 *     antigüedad y —desde el hallazgo de la fase 11— el conteo de faltas de su primer periodo;
 *     y sin sueldo se AVISA, no se bloquea (criterio del dueño).
 */
class ImportacionDePlantilla
{
    /** Lo que el archivo puede traer. La primera es la única obligatoria junto con la fecha. */
    public const COLUMNAS = [
        'nombre', 'correo', 'puesto', 'fecha_ingreso', 'sueldo', 'periodicidad',
        'turno_inicio', 'turno_fin', 'dia_descanso', 'telefono',
        'numero_empleado', 'curp', 'rfc', 'nss', 'rol',
    ];

    /**
     * Como lo escribe una persona -> como lo llama el sistema. Quien llena el archivo pone
     * "Fecha de Ingreso" o "Salario", no `fecha_ingreso`; rechazarle el archivo por eso seria
     * hacerle aprender nuestro vocabulario para poder darnos sus datos.
     */
    private const ALIAS = [
        'fecha_de_ingreso' => 'fecha_ingreso', 'ingreso' => 'fecha_ingreso', 'alta' => 'fecha_ingreso',
        'nombre_completo' => 'nombre', 'colaborador' => 'nombre', 'empleado' => 'nombre',
        'email' => 'correo', 'e_mail' => 'correo', 'correo_electronico' => 'correo',
        'salario' => 'sueldo', 'sueldo_bruto' => 'sueldo',
        'puesto_de_trabajo' => 'puesto', 'cargo' => 'puesto',
        'dia_de_descanso' => 'dia_descanso', 'descanso' => 'dia_descanso',
        'numero_de_empleado' => 'numero_empleado', 'no_empleado' => 'numero_empleado',
        'hora_de_entrada' => 'turno_inicio', 'entrada' => 'turno_inicio',
        'hora_de_salida' => 'turno_fin', 'salida' => 'turno_fin',
        'telefono_celular' => 'telefono', 'celular' => 'telefono',
    ];

    private const PERIODICIDADES = ['diario', 'semanal', 'quincenal', 'mensual'];
    private const ROLES = ['admin', 'supervisor', 'empleado'];
    public const MAX_RENGLONES = 500;

    /**
     * Lee el CSV y devuelve el veredicto SIN escribir nada.
     *
     * @return array{renglones: array, errores: array, resumen: array}
     */
    public function revisar(string $csv, int $tenantId): array
    {
        [$cabecera, $filas] = $this->parsear($csv);

        $errores = [];
        if ($cabecera === null) {
            return $this->veredicto([], ['El archivo está vacío o no se pudo leer como CSV.'], $tenantId);
        }
        if (!in_array('nombre', $cabecera, true)) {
            $errores[] = 'El archivo no tiene una columna "nombre". Descarga la plantilla y usa sus encabezados.';
        }
        if (count($filas) > self::MAX_RENGLONES) {
            $errores[] = 'El archivo trae ' . count($filas) . ' renglones; el máximo por importación es ' . self::MAX_RENGLONES . '.';
        }
        if (!empty($errores)) {
            return $this->veredicto([], $errores, $tenantId);
        }

        $puestos = JobRole::where('tenant_id', $tenantId)->get()
            ->mapWithKeys(fn ($p) => [$this->normalizar($p->name) => $p->id])->all();

        $correosDelArchivo = [];
        $renglones = [];

        foreach ($filas as $i => $fila) {
            // +2: el humano cuenta desde 1 y la primera línea es la cabecera.
            $numero = $i + 2;
            $r = $this->aRenglon($cabecera, $fila);
            $problemas = [];

            $nombre = trim((string) ($r['nombre'] ?? ''));
            if ($nombre === '') {
                $problemas[] = 'falta el nombre';
            }

            // Correo: OPCIONAL. Si viene, tiene que ser válido, no estar repetido en el archivo
            // y no existir ya en la plataforma (el correo es la llave de acceso).
            $correo = trim((string) ($r['correo'] ?? ''));
            $correo = $correo === '' ? null : mb_strtolower($correo);
            if ($correo !== null) {
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    $problemas[] = "el correo \"{$correo}\" no es válido";
                } elseif (isset($correosDelArchivo[$correo])) {
                    $problemas[] = "el correo \"{$correo}\" está repetido (renglón {$correosDelArchivo[$correo]})";
                } elseif (User::withoutGlobalScopes()->where('email', $correo)->exists()) {
                    $problemas[] = "el correo \"{$correo}\" ya está registrado en la plataforma";
                } else {
                    $correosDelArchivo[$correo] = $numero;
                }
            }

            // Fecha de ingreso: obligatoria. De ella cuelga el conteo de faltas del primer
            // periodo — sin ella, a quien entra a mitad de semana se le cobran como faltas los
            // días anteriores a su alta (el hallazgo de la fase 11).
            $fecha = trim((string) ($r['fecha_ingreso'] ?? ''));
            $fechaIso = null;
            if ($fecha === '') {
                $problemas[] = 'falta la fecha de ingreso (aaaa-mm-dd)';
            } else {
                try {
                    $fechaIso = Carbon::parse($fecha)->toDateString();
                    if (Carbon::parse($fechaIso)->isFuture()) {
                        $problemas[] = "la fecha de ingreso {$fechaIso} está en el futuro";
                    }
                } catch (\Throwable $e) {
                    $problemas[] = "la fecha de ingreso \"{$fecha}\" no se entiende (usa aaaa-mm-dd)";
                }
            }

            // Puesto POR NOMBRE: quien llena un CSV escribe "Cajera", no un número.
            $puesto = trim((string) ($r['puesto'] ?? ''));
            $puestoId = null;
            if ($puesto !== '') {
                $puestoId = $puestos[$this->normalizar($puesto)] ?? null;
                if ($puestoId === null) {
                    $disponibles = empty($puestos) ? 'esta empresa aún no tiene puestos' : implode(', ', array_keys($puestos));
                    $problemas[] = "el puesto \"{$puesto}\" no existe en esta empresa (hay: {$disponibles})";
                }
            }

            $rol = mb_strtolower(trim((string) ($r['rol'] ?? 'empleado'))) ?: 'empleado';
            if (!in_array($rol, self::ROLES, true)) {
                $problemas[] = "el rol \"{$rol}\" no existe (usa: " . implode(', ', self::ROLES) . ')';
            }

            $periodicidad = mb_strtolower(trim((string) ($r['periodicidad'] ?? ''))) ?: null;
            if ($periodicidad !== null && !in_array($periodicidad, self::PERIODICIDADES, true)) {
                $problemas[] = "la periodicidad \"{$periodicidad}\" no existe (usa: " . implode(', ', self::PERIODICIDADES) . ')';
            }

            $sueldo = trim((string) ($r['sueldo'] ?? ''));
            $sueldo = $sueldo === '' ? null : (float) str_replace([',', '$', ' '], '', $sueldo);
            if ($sueldo !== null && $sueldo <= 0) {
                $problemas[] = 'el sueldo tiene que ser mayor que cero (déjalo vacío si aún no se define)';
            }

            $avisos = [];
            if ($sueldo === null) {
                // Criterio del dueño: sin sueldo se AVISA, no se bloquea.
                $avisos[] = 'sin sueldo: entrará marcado como pendiente y no se le calculará pre-nómina';
            } elseif ($periodicidad === null) {
                $avisos[] = 'sueldo sin periodicidad: se tomará como mensual';
            }
            if ($correo === null) {
                $avisos[] = 'sin correo: entra por kiosco con su PIN (no se le inventa uno)';
            }

            $renglones[] = [
                'renglon' => $numero,
                'nombre' => $nombre,
                'correo' => $correo,
                'puesto' => $puesto ?: null,
                'job_role_id' => $puestoId,
                'fecha_ingreso' => $fechaIso,
                'sueldo' => $sueldo,
                'periodicidad' => $periodicidad,
                'rol' => $rol,
                'turno_inicio' => trim((string) ($r['turno_inicio'] ?? '')) ?: null,
                'turno_fin' => trim((string) ($r['turno_fin'] ?? '')) ?: null,
                'dia_descanso' => trim((string) ($r['dia_descanso'] ?? '')) ?: null,
                'telefono' => trim((string) ($r['telefono'] ?? '')) ?: null,
                'numero_empleado' => trim((string) ($r['numero_empleado'] ?? '')) ?: null,
                'curp' => trim((string) ($r['curp'] ?? '')) ?: null,
                'rfc' => trim((string) ($r['rfc'] ?? '')) ?: null,
                'nss' => trim((string) ($r['nss'] ?? '')) ?: null,
                'problemas' => $problemas,
                'avisos' => $avisos,
            ];

            foreach ($problemas as $p) {
                $errores[] = "Renglón {$numero}: {$p}.";
            }
        }

        return $this->veredicto($renglones, $errores, $tenantId);
    }

    /**
     * Escribe la plantilla. Vuelve a revisar por dentro: nadie importa sin pasar la aduana, ni
     * aunque llame directo a este método.
     *
     * @return array{creados: int, renglones: array}
     */
    public function importar(string $csv, int $tenantId): array
    {
        $veredicto = $this->revisar($csv, $tenantId);

        if (!empty($veredicto['errores'])) {
            throw new \RuntimeException(
                'El archivo tiene ' . count($veredicto['errores']) . ' problema(s); no se dio de alta a nadie.'
            );
        }
        if (empty($veredicto['renglones'])) {
            throw new \RuntimeException('El archivo no trae ningún colaborador.');
        }

        $creados = 0;

        DB::transaction(function () use ($veredicto, $tenantId, &$creados) {
            foreach ($veredicto['renglones'] as $r) {
                $datos = [
                    'name' => $r['nombre'],
                    'email' => $r['correo'],
                    'job_role_id' => $r['job_role_id'],
                    'hire_date' => $r['fecha_ingreso'],
                    'is_active_employee' => true,
                    'phone' => $r['telefono'],
                    'employee_id' => $r['numero_empleado'],
                    'curp' => $r['curp'],
                    'rfc' => $r['rfc'],
                    'nss' => $r['nss'],
                    'restDay' => $r['dia_descanso'],
                    'shiftStart' => $r['turno_inicio'],
                    'shiftEnd' => $r['turno_fin'],
                ];

                // El dinero, con la MISMA derivación que el alta de uno: la periodicidad decide
                // el salario diario, que es lo que consume el motor.
                if ($r['sueldo'] !== null) {
                    $datos['base_salary'] = $r['sueldo'];
                    $datos['salary'] = $r['sueldo'];
                    $periodicidad = $r['periodicidad'] ?? 'mensual';
                    $datos['salario_diario'] = SalarioDiario::desde((float) $r['sueldo'], $periodicidad);
                    $datos['periodicidad_captura'] = $periodicidad;
                }

                // Turno: si el archivo no lo trae, se hereda el de la empresa (con NULL el reloj
                // asumía 09:00 para todo el mundo y cobraba retardos desde el primer día).
                $datos = HorarioDeLaEmpresa::completar($datos, $tenantId);

                $usuario = User::create([
                    'name' => $r['nombre'],
                    'email' => $r['correo'],
                    // Aleatoria de 32: nadie la conoce, ni quien importa. La persona fija la suya
                    // al activar su cuenta con el PIN.
                    'password' => Hash::make(Str::random(32)),
                    'role' => $r['rol'],
                    'job_role_id' => $r['job_role_id'],
                    'tenant_id' => $tenantId,
                    'is_active' => true,
                ]);

                $datos['tenant_id'] = $tenantId;
                $datos['user_id'] = $usuario->id;
                Employee::create($datos);
                $creados++;
            }
        });

        return ['creados' => $creados, 'renglones' => $veredicto['renglones']];
    }

    /** El archivo de ejemplo que se descarga: los encabezados y un renglón que se entiende solo. */
    public function plantilla(): string
    {
        $ejemplo = [
            'Rosa Martínez Loera', 'rosa@ejemplo.com', 'Cajera', '2026-01-15', '7500', 'mensual',
            '09:00', '18:00', 'Domingo', '6641234567', 'EMP-014', '', '', '', 'empleado',
        ];

        $salida = fopen('php://temp', 'r+');
        fputcsv($salida, self::COLUMNAS);
        fputcsv($salida, $ejemplo);
        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        // BOM para que Excel en español abra los acentos bien (mismo criterio que los reportes).
        return "\xEF\xBB\xBF" . $csv;
    }

    // ------------------------------------------------------------------ interno

    private function veredicto(array $renglones, array $errores, int $tenantId): array
    {
        $activos = Employee::where('tenant_id', $tenantId)->where('is_active_employee', '!=', false)->count();

        return [
            'renglones' => $renglones,
            'errores' => $errores,
            'resumen' => [
                'en_el_archivo' => count($renglones),
                'listos' => count(array_filter($renglones, fn ($r) => empty($r['problemas']))),
                'con_problema' => count(array_filter($renglones, fn ($r) => !empty($r['problemas']))),
                'con_aviso' => count(array_filter($renglones, fn ($r) => !empty($r['avisos']))),
                'plantilla_actual' => $activos,
            ],
        ];
    }

    /** @return array{0: ?array, 1: array} cabecera normalizada y filas */
    private function parsear(string $csv): array
    {
        // Excel en español guarda con BOM y a veces con punto y coma.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lineas = preg_split('/\r\n|\r|\n/', trim($csv));
        $lineas = array_values(array_filter($lineas, fn ($l) => trim($l) !== ''));

        if (empty($lineas)) {
            return [null, []];
        }

        $separador = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ',';

        $cabecera = array_map(
            function ($c) {
                $n = $this->normalizar($c);

                return self::ALIAS[$n] ?? $n;
            },
            str_getcsv(array_shift($lineas), $separador)
        );

        $filas = array_map(fn ($l) => str_getcsv($l, $separador), $lineas);

        return [$cabecera, $filas];
    }

    private function aRenglon(array $cabecera, array $fila): array
    {
        $r = [];
        foreach ($cabecera as $i => $columna) {
            $r[$columna] = $fila[$i] ?? null;
        }

        return $r;
    }

    /** Minúsculas sin acentos ni espacios: "Fecha de Ingreso" y "fecha_ingreso" son lo mismo. */
    private function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/[^a-z0-9]+/', '_', $t);
    }
}
