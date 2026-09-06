<?php

namespace App\Console\Commands;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\User;
use App\Support\BitacoraDeAsistencia;
use App\Support\FichajesVigentes;
use App\Support\HuellaDelColaborador;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PURGA DE RETENCIÓN — el techo de los cinco años (2026-09-05).
 *
 * El sistema ya tenía PISO: "Eliminar definitivamente" se niega a borrar a quien tiene fichajes,
 * recibos o expediente y archiva citando el art. 804 de la LFT. Lo que no tenía era TECHO: pasados
 * los cinco años nada caducaba nunca. Conservar datos personales sin plazo tiene su propio problema
 * (la LFPDPPP obliga a suprimirlos cuando dejan de ser necesarios) y un riesgo evidente: lo que ya
 * no existe no se puede filtrar.
 *
 * SE SELECCIONA POR PERSONA, NO POR FECHA DE FICHAJE. Es la diferencia entre cumplir y hacer daño:
 * el plazo del 804 corre desde que termina la relación laboral, no desde que ocurrió cada marca.
 * Purgar "todo lo anterior a 2021" le borraría los primeros años a quien lleva ocho aquí y sigue
 * en nómina — justo la evidencia con la que la empresa se defendería de una demanda suya.
 *
 * ADIVINA O AVISA, NUNCA LAS DOS COSAS (criterio del proyecto, 2026-08-22). Tres situaciones se
 * LISTAN y NO se purgan, porque en las tres el dato disponible se contradice consigo mismo:
 *
 *   · **Baja sin fecha.** Las bajas anteriores al 2026-08-16 no tienen `termination_date` (la
 *     columna no existía). Sin fecha no hay plazo que contar: no se inventa una.
 *   · **Fecha vencida pero la persona figura ACTIVA.** Alguien que está trabajando no se purga
 *     porque arrastre una fecha vieja. (La raíz ya está tapada: reincorporar limpia la fecha, ver
 *     EmployeeController::update. Esto es el segundo cerrojo, por lo que quedó de antes.)
 *   · **Fichajes POSTERIORES a la fecha de baja.** Si siguió fichando, la baja no fue real o la
 *     fecha está mal. En cualquiera de los dos casos el plazo no ha empezado a correr.
 *
 * Y por encima de todo, la RESERVA LEGAL (`legal_hold_at`): quien tiene un juicio abierto queda
 * fuera pase el tiempo que pase. La prueba que el patrón destruyó se presume en su contra
 * (LFT 784/804); un plazo de retención que corre por encima de un litigio no es cumplimiento.
 *
 * SIMULACRO POR DEFECTO. Sólo `--aplicar` borra, y **sólo un humano lo escribe**: el agendado
 * mensual corre el simulacro y nada más (bootstrap/app.php). Misma filosofía que el paso 3 del
 * RFC de la bitácora: observar antes de confiar.
 *
 * CADA PERSONA EN SU PROPIA TRANSACCIÓN. Si una falla —un archivo bloqueado, una FK inesperada—
 * las demás siguen y esa queda entera, sin purgar a medias.
 */
#[Signature('datos:purgar-vencidos
    {--aplicar : Borra de verdad (sin esto es un simulacro)}
    {--tenant= : Sólo esta empresa}
    {--anios=5 : Años de retención desde la fecha de baja (mínimo 5, art. 804 LFT)}
    {--empleado= : Sólo este expediente (id de employees)}')]
#[Description('Purga los datos personales de quien lleva más de N años dado de baja. Simulacro por defecto.')]
class PurgarDatosVencidos extends Command
{
    public function handle(): int
    {
        $anios = (int) $this->option('anios');
        $aplicar = (bool) $this->option('aplicar');

        // El piso del art. 804 LFT no se negocia desde la línea de comandos. La función de base
        // de datos lo impone otra vez por su cuenta (ver la migración del SECURITY DEFINER): que
        // se pueda pedir menos y que nadie lo conceda es la idea.
        if ($anios < 5) {
            $this->error('El mínimo legal de conservación son 5 años (art. 804 LFT). --anios=' . $anios . ' no se acepta.');

            return self::FAILURE;
        }

        $empresas = DB::table('tenants')->orderBy('id');
        if ($this->option('tenant')) {
            $empresas->where('id', (int) $this->option('tenant'));
        }
        $empresas = $empresas->get(['id', 'name']);

        $candidatos = [];
        $guardas = [];
        $reservas = [];
        $noVencen = 0;

        foreach ($empresas as $empresa) {
            // El plazo se mide en la zona del inquilino: un corte calculado en UTC adelanta o
            // atrasa el vencimiento un día, y ese día es el que separa "puedo" de "no puedo".
            $tz = TenantTimezone::for($empresa->id);
            $corte = Carbon::now($tz)->subYears($anios)->toDateString();

            // EN CONSOLA EL TenantScope NO APLICA (ver App\Scopes\TenantScope): el filtro por
            // empresa va a mano, siempre. Un `where` olvidado aquí purga a todas las empresas.
            $consulta = Employee::withoutGlobalScopes()
                ->withTrashed()
                ->where('tenant_id', $empresa->id)
                ->whereNull('purged_at');

            if ($this->option('empleado')) {
                $consulta->where('id', (int) $this->option('empleado'));
            }

            foreach ($consulta->orderBy('id')->get() as $expediente) {
                $activo = $expediente->is_active_employee !== false;
                $baja = $expediente->termination_date
                    ? Carbon::parse($expediente->termination_date)->toDateString()
                    : null;

                $fila = [
                    'empresa' => $empresa,
                    'expediente' => $expediente,
                    'corte' => $corte,
                    'baja' => $baja,
                ];

                if ($expediente->legal_hold_at) {
                    $fila['nota'] = 'reserva legal desde ' . $expediente->legal_hold_at->toDateString()
                        . ' · ' . ($expediente->legal_hold_reason ?: 'sin motivo escrito');
                    $reservas[] = $fila;
                    continue;
                }

                if ($activo && $baja !== null) {
                    $fila['nota'] = $baja <= $corte
                        ? 'figura ACTIVO y arrastra una fecha de baja YA VENCIDA (' . $baja . ')'
                        : 'figura ACTIVO y arrastra una fecha de baja (' . $baja . ')';
                    $guardas[] = $fila;
                    continue;
                }

                if ($activo) {
                    continue; // en plantilla y sin fecha de baja: lo normal.
                }

                if ($baja === null) {
                    $fila['nota'] = 'dado de baja SIN FECHA: no hay plazo que contar';
                    $guardas[] = $fila;
                    continue;
                }

                if ($baja > $corte) {
                    $noVencen++;
                    continue;
                }

                $ultimoFichaje = $this->ultimoFichajeDespuesDe($expediente, $baja);
                if ($ultimoFichaje !== null) {
                    $fila['nota'] = 'fichó el ' . $ultimoFichaje . ', DESPUÉS de su baja (' . $baja . '): la baja no es real';
                    $guardas[] = $fila;
                    continue;
                }

                $candidatos[] = $fila;
            }
        }

        $this->imprimirGuardas($guardas, $reservas, $noVencen);

        if (empty($candidatos)) {
            $this->newLine();
            $this->info('✔ Nadie cumple ' . $anios . ' años desde su baja. No hay nada que purgar.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($aplicar
            ? '── PURGANDO (esto borra de verdad) ──'
            : '── SIMULACRO: esto es lo que se borraría. Nada se toca. Use --aplicar ──');

        $tabla = [];
        foreach ($candidatos as $fila) {
            $conteos = $this->contar($fila['expediente']);
            $tabla[] = [
                $fila['empresa']->id . ' · ' . Str::limit($fila['empresa']->name, 18),
                $fila['expediente']->id,
                Str::limit((string) $fila['expediente']->name, 22),
                $fila['baja'],
                $conteos['fichajes'],
                $conteos['historial'],
                $conteos['recibos'],
                $conteos['expediente'],
                $conteos['comedor'],
                $conteos['bitacora'],
                $conteos['otros'],
            ];
        }

        $this->table(
            ['Empresa', 'Exp.', 'Persona', 'Baja', 'Fichajes', 'Historial', 'Recibos', 'Docs', 'Comedor', 'Bitácora', 'Otros'],
            $tabla
        );

        $this->avisarDeLaWiki($candidatos);

        if (!$aplicar) {
            $this->newLine();
            $this->warn(count($candidatos) . ' persona(s) quedarían anonimizadas y sin datos. NADA se borró.');
            $this->line('El expediente y la cuenta NO se eliminan: se anonimizan (ver la nota de anonimizar()).');

            return self::SUCCESS;
        }

        $purgadas = 0;
        $fallidas = 0;

        foreach ($candidatos as $fila) {
            $expediente = $fila['expediente'];

            try {
                // CADA PERSONA EN SU PROPIA TRANSACCIÓN: si una falla, las demás siguen.
                //
                // `firmando(null, 'purga_lft_5_anios')` declara la intención EN la transacción,
                // así que las filas DELETE que el trigger escribe en `time_entries_historial`
                // quedan atribuidas a esta purga... justo antes de que las borremos nosotros. Que
                // el historial registre su propia desaparición y luego desaparezca es exactamente
                // lo que hace falta para que la purga no sea un movimiento de datos disfrazado.
                $archivos = BitacoraDeAsistencia::firmando(
                    null,
                    'purga_lft_5_anios',
                    null,
                    fn () => $this->purgarA($expediente, $fila['corte'])
                );

                // Los archivos SE BORRAN DESPUÉS DEL COMMIT, nunca dentro: si la transacción se
                // revirtiera, la fila volvería y el archivo ya no estaría. Perder el registro es
                // recuperable; perder el documento no.
                $this->borrarArchivos($archivos);

                $purgadas++;
                $this->line('   · ' . $expediente->id . ' — purgado y anonimizado.');
            } catch (\Throwable $e) {
                $fallidas++;
                $this->error('   ✗ Expediente ' . $expediente->id . ': ' . $e->getMessage());
                $this->line('     No se purgó NADA de esta persona (su transacción se revirtió entera). Las demás siguen.');
            }
        }

        $this->newLine();
        $this->info($purgadas . ' persona(s) purgada(s) y anonimizada(s).');
        if ($fallidas > 0) {
            $this->error($fallidas . ' fallaron y quedaron intactas. Revísalas antes de volver a correr.');
        }
        $this->line('Recuerde: el respaldo diario conserva 14 días, así que lo purgado sigue vivo');
        $this->line('dentro de los respaldos durante dos semanas más.');

        return $fallidas > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ------------------------------------------------------------------ selección

    /** La fecha del último fichaje POSTERIOR a la baja, o null si no hay ninguno. */
    private function ultimoFichajeDespuesDe(Employee $expediente, string $baja): ?string
    {
        if (!$expediente->user_id) {
            return null;
        }

        // `todos()` y no `query()` a propósito: para decidir si esta persona siguió trabajando
        // cuentan TAMBIÉN los fichajes anulados por una corrección. Un fichaje anulado sigue
        // siendo la prueba de que alguien estuvo aquí ese día.
        return FichajesVigentes::todos()
            ->where('user_id', $expediente->user_id)
            ->where('date', '>', $baja)
            ->max('date');
    }

    // ------------------------------------------------------------------ conteos

    /** @return array<string,int> */
    private function contar(Employee $expediente): array
    {
        $userId = $expediente->user_id;

        $fichajes = $userId
            ? FichajesVigentes::todos()->where('user_id', $userId)->count()
            : 0;

        $conteos = [
            'fichajes' => $fichajes,
            'historial' => $this->contarHistorial($expediente),
            'recibos' => $this->contarTabla('weekly_payrolls', ['employee_id'], $expediente->id),
            'expediente' => $this->contarTabla('employee_documents', ['employee_id'], $expediente->id),
            'comedor' => $userId ? $this->contarTabla('meal_photo_evidences', ['employee_id'], $userId) : 0,
            'bitacora' => $userId
                ? $this->contarTabla('audit_logs', ['user_id'], $userId)
                    + $this->contarTabla('saas_audit_logs', ['user_id'], $userId)
                : 0,
            'otros' => 0,
        ];

        $yaContadas = ['weekly_payrolls', 'employee_documents', 'meal_photo_evidences', 'audit_logs', 'saas_audit_logs'];

        foreach (HuellaDelColaborador::plan() as $paso) {
            if (in_array($paso['tabla'], $yaContadas, true)) {
                continue;
            }
            $id = $paso['llave'] === 'usuario' ? $userId : $expediente->id;
            if ($id === null) {
                continue;
            }
            $conteos['otros'] += $this->contarTabla($paso['tabla'], $paso['columnas'], $id);
        }

        return $conteos;
    }

    private function contarTabla(string $tabla, array $columnas, int $id): int
    {
        return $this->filasDe($tabla, $columnas, $id)->count();
    }

    /** El constructor de consulta que localiza a esta persona en esa tabla. */
    private function filasDe(string $tabla, array $columnas, int $id)
    {
        // SIN filtro por tenant a propósito: `users.id` y `employees.id` son únicos en toda la
        // base, así que el id ya identifica exactamente. Añadir `tenant_id` sólo podría RESTAR
        // filas (varias de estas tablas lo tienen nulo o ni siquiera la columna) y dejar datos
        // personales sin purgar creyendo que se purgaron.
        return DB::table($tabla)->where(function ($q) use ($columnas, $id) {
            foreach ($columnas as $columna) {
                $q->orWhere($columna, $id);
            }
        });
    }

    private function contarHistorial(Employee $expediente): int
    {
        if (!$expediente->user_id) {
            return 0;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return (int) DB::table('time_entries_historial')
                ->where('tenant_id', $expediente->tenant_id)
                ->whereRaw(
                    "COALESCE(NULLIF(fila_antes->>'user_id', ''), NULLIF(fila_despues->>'user_id', ''))::bigint = ?",
                    [$expediente->user_id]
                )
                ->count();
        }

        // sqlite (la suite) no tiene el trigger ni escribe historial: se cuenta por los ids de
        // fichaje, que es lo que se puede saber ahí.
        $ids = FichajesVigentes::todos()->where('user_id', $expediente->user_id)->pluck('id')->all();

        return $this->historialPorIds($expediente->tenant_id, $ids)->count();
    }

    private function historialPorIds(int $tenantId, array $ids)
    {
        return DB::table('time_entries_historial')
            ->where('tenant_id', $tenantId)
            ->whereIn('time_entry_id', $ids ?: [0]);
    }

    // ------------------------------------------------------------------ borrado

    /**
     * Todo lo de una persona, dentro de la transacción firmada. Devuelve las rutas de los
     * archivos que hay que borrar del disco DESPUÉS del commit.
     *
     * @return array<int,array{ruta:string,carpeta:string}>
     */
    private function purgarA(Employee $expediente, string $corte): array
    {
        $userId = $expediente->user_id;
        $tenantId = (int) $expediente->tenant_id;

        // Las rutas se recogen ANTES de borrar las filas: después ya no hay de dónde sacarlas.
        $archivos = $this->rutasDeArchivos($expediente);

        if ($userId) {
            // 1. Los fichajes. El trigger de Postgres copia cada fila borrada al historial,
            //    firmada con `purga_lft_5_anios` gracias a BitacoraDeAsistencia.
            $ids = FichajesVigentes::todos()->where('user_id', $userId)->pluck('id')->all();
            FichajesVigentes::todos()->where('user_id', $userId)->delete();

            // 2. Y DESPUÉS el historial, incluidas las filas que el trigger acaba de escribir.
            //    Sin este paso la purga no purga nada: sólo mueve el dato personal de una tabla
            //    a otra y deja al dueño creyendo que cumplió.
            $this->purgarHistorial($tenantId, (int) $userId, $corte, $ids);
        }

        // 3. Todo lo demás que cuelga de la persona, según el mapa declarado.
        foreach (HuellaDelColaborador::plan() as $paso) {
            $id = $paso['llave'] === 'usuario' ? $userId : $expediente->id;
            if ($id === null) {
                continue;
            }
            $this->filasDe($paso['tabla'], $paso['columnas'], (int) $id)->delete();
        }

        // 4. Expediente y cuenta: se ANONIMIZAN.
        $this->anonimizar($expediente);

        return $archivos;
    }

    /**
     * Borra el historial de asistencia de esa persona.
     *
     * En Postgres NO se borra desde la aplicación, sino invocando `purgar_historial_de_persona`,
     * una función SECURITY DEFINER que comprueba por su cuenta el plazo y la reserva legal (ver su
     * migración). El paso 3 del RFC de la bitácora inmutable le quita a la aplicación el permiso
     * de DELETE sobre `time_entries_historial` —ése es el punto de una bitácora inmutable— y
     * devolvérselo para poder ejercer un caso al año desharía la garantía entera. Si la función no
     * existe o no tiene privilegios, esto REVIENTA la transacción de esa persona y se reporta: es
     * preferible a dar por purgado lo que sigue ahí.
     */
    private function purgarHistorial(int $tenantId, int $userId, string $corte, array $ids): int
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $fila = DB::selectOne(
                'SELECT purgar_historial_de_persona(?, ?, ?::date) AS borradas',
                [$tenantId, $userId, $corte]
            );

            return (int) ($fila->borradas ?? 0);
        }

        // sqlite y demás: no hay trigger que escriba historial ni permiso revocado que sortear.
        // Se borra por los ids de fichaje que se acaban de quitar, en tandas para no chocar con
        // el límite de parámetros del driver.
        $borradas = 0;
        foreach (array_chunk($ids, 500) as $tanda) {
            $borradas += $this->historialPorIds($tenantId, $tanda)->delete();
        }

        return $borradas;
    }

    /**
     * EL EXPEDIENTE Y LA CUENTA NO SE BORRAN: SE VACÍAN.
     *
     * Borrar la fila de `users` arrastraría por CASCADE media base de datos (las llaves foráneas
     * de asistencia, comedor, tareas y aperturas son `onDelete('cascade')`) y además chocaría con
     * al menos una que no lo es. Pero el motivo de fondo no es técnico: **el reporte de rotación
     * necesita seguir contando esta fila**. Si el expediente desaparece, la empresa deja de saber
     * cuánta gente entró y salió en aquellos años, y un indicador que se recalcula distinto cada
     * vez que vence una retención no sirve para nada.
     *
     * Así que se conserva el ESQUELETO —id, alta, baja, motivo de baja y puesto: lo que hace falta
     * para contar y para saber cuánto duró— y se vacía la PERSONA: nombre, correo, teléfono, CURP,
     * RFC, NSS, domicilio, contacto de emergencia, foto, PINes, tokens y sueldos. Lo que queda no
     * identifica a nadie.
     */
    private function anonimizar(Employee $expediente): void
    {
        $correoAnterior = $expediente->email;

        // forceFill: estas columnas están FUERA de $fillable a propósito (ver la migración).
        $expediente->forceFill([
            'name' => 'Colaborador purgado',
            'email' => null,
            'phone' => null,
            'employee_id' => null, // número de empleado: identifica a la persona en papeles internos
            'curp' => null,
            'rfc' => null,
            'nss' => null,
            'address' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'avatar' => null,
            'pin_code' => null,
            'security_pin' => null,
            'invite_token' => null,
            'kiosk_pin_hash' => null,
            'kiosk_pin_lookup' => null,
            'salary' => null,
            'base_salary' => null,
            'salario_diario' => null,
            'clock_preferences' => null,
            'allowed_modules' => null,
            'allowed_features' => null,
            'report_to' => null,
            'purged_at' => now(),
        ])->save();

        if (!$expediente->user_id) {
            return;
        }

        $cuenta = User::withoutGlobalScopes()->withTrashed()->find($expediente->user_id);
        if (!$cuenta) {
            return;
        }

        $cuenta->forceFill([
            'name' => 'Colaborador purgado',
            'email' => null, // users.email admite NULL desde 2026-08-26
            'phone' => null,
            // El cast 'hashed' se encarga: se guarda una contraseña que nadie conoce ni conocerá.
            'password' => Str::random(40),
            'google_id' => null,
            'apple_id' => null,
            'samsung_id' => null,
            'qr_token' => null,
            'fcm_token' => null,
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'biometric_key' => null,
            'avatar' => null,
            'remember_token' => null,
            'is_active' => false,
        ])->save();

        // Los tokens de sesión se REVOCAN: una sesión viva sobre una cuenta anonimizada seguiría
        // entrando a la aplicación.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $cuenta->id)
            ->delete();

        if ($correoAnterior) {
            DB::table('password_reset_tokens')->where('email', $correoAnterior)->delete();
        }
    }

    // ------------------------------------------------------------------ archivos

    /**
     * Rutas de los archivos de esta persona en el disco privado, con la carpeta DENTRO DE LA CUAL
     * se permite borrar cada una.
     *
     * @return array<int,array{ruta:string,carpeta:string}>
     */
    private function rutasDeArchivos(Employee $expediente): array
    {
        $archivos = [];

        foreach (DB::table('employee_documents')->where('employee_id', $expediente->id)->pluck('path') as $ruta) {
            if ($ruta) {
                $archivos[] = [
                    'ruta' => (string) $ruta,
                    'carpeta' => 'expedientes/' . $expediente->tenant_id . '/' . $expediente->id,
                ];
            }
        }

        if ($expediente->user_id) {
            // OJO: en meal_photo_evidences la columna `employee_id` guarda un users.id.
            foreach (DB::table('meal_photo_evidences')->where('employee_id', $expediente->user_id)->pluck('path') as $ruta) {
                if ($ruta) {
                    $archivos[] = [
                        'ruta' => (string) $ruta,
                        'carpeta' => 'meal-evidence/' . $expediente->tenant_id,
                    ];
                }
            }
        }

        return $archivos;
    }

    /** @param array<int,array{ruta:string,carpeta:string}> $archivos */
    private function borrarArchivos(array $archivos): void
    {
        $disco = Storage::disk('local');

        foreach ($archivos as $archivo) {
            // RUTA ACOTADA, igual que en PurgeClockPhotos: se comprueba con realpath que el
            // archivo cae DENTRO de su carpeta antes de tocarlo. En agosto un `photo_url` con
            // "../.env" guardado por un cliente llegaba a un @unlink() y borraba el .env del
            // servidor. Aquí las rutas las genera el servidor, pero la regla no depende de eso:
            // nunca se borra por una ruta sin comprobar dónde acaba.
            $raiz = realpath($disco->path($archivo['carpeta']));
            $destino = realpath($disco->path($archivo['ruta']));

            if ($destino === false) {
                continue; // no existe: nada que borrar
            }

            if (!$raiz || !str_starts_with($destino, $raiz . DIRECTORY_SEPARATOR)) {
                $this->warn('     Ruta fuera de su carpeta, NO se borra: ' . $archivo['ruta']);
                continue;
            }

            @unlink($destino);
        }
    }

    // ------------------------------------------------------------------ impresión

    /**
     * La Wiki (Obsidian) tiene su PROPIA tabla de cuentas, `obsidian_users`, sin ninguna llave que
     * la ate al colaborador: lo único en común es el correo. Emparejar personas por correo es
     * adivinar, y una coincidencia equivocada borraría la cuenta de alguien más — así que no se
     * purga. Pero tampoco se calla: se avisa, con nombre y correo, para que quien corre el comando
     * sepa que ahí queda un dato personal y decida a mano. Avisa, no adivina.
     */
    private function avisarDeLaWiki(array $candidatos): void
    {
        $pendientes = [];

        foreach ($candidatos as $fila) {
            $correo = $fila['expediente']->email;
            if (!$correo) {
                continue;
            }

            $cuentaWiki = DB::table('obsidian_users')
                ->where('tenant_id', $fila['expediente']->tenant_id)
                ->where('email', $correo)
                ->exists();

            if ($cuentaWiki) {
                $pendientes[] = $fila['expediente']->name . ' <' . $correo . '>';
            }
        }

        if (empty($pendientes)) {
            return;
        }

        $this->newLine();
        $this->warn('LA WIKI QUEDA FUERA y estas personas tienen cuenta ahí (mismo correo):');
        foreach ($pendientes as $quien) {
            $this->line('   · ' . $quien);
        }
        $this->line('`obsidian_users` no tiene ninguna llave hacia el expediente: emparejar por correo');
        $this->line('sería adivinar, y equivocarse borraría la cuenta de otra persona. Revísalas a mano.');
    }

    private function imprimirGuardas(array $guardas, array $reservas, int $noVencen): void
    {
        $this->newLine();

        if (!empty($reservas)) {
            $this->line('── RESERVA LEGAL: fuera de la purga mientras siga puesta ──');
            $this->table(
                ['Empresa', 'Exp.', 'Persona', 'Motivo'],
                array_map(fn ($f) => [
                    $f['empresa']->id . ' · ' . Str::limit($f['empresa']->name, 18),
                    $f['expediente']->id,
                    Str::limit((string) $f['expediente']->name, 24),
                    $f['nota'],
                ], $reservas)
            );
        }

        if (!empty($guardas)) {
            $this->line('── SE LISTAN Y NO SE PURGAN: el dato se contradice consigo mismo ──');
            $this->table(
                ['Empresa', 'Exp.', 'Persona', 'Por qué no se toca'],
                array_map(fn ($f) => [
                    $f['empresa']->id . ' · ' . Str::limit($f['empresa']->name, 18),
                    $f['expediente']->id,
                    Str::limit((string) $f['expediente']->name, 24),
                    $f['nota'],
                ], $guardas)
            );
            $this->warn('Ninguna de estas personas se purga, ni con --aplicar. El sistema avisa; no adivina.');
        }

        if ($noVencen > 0) {
            $this->line($noVencen . ' expediente(s) con baja registrada todavía dentro del plazo de retención.');
        }
    }
}
