<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Cierra de verdad la bitácora inmutable de asistencia (paso 3 del RFC, 2026-09-05).
 *
 * QUÉ PROBLEMA RESUELVE, EN UNA FRASE: hoy la aplicación entra a Postgres como `postgres`, que es
 * **superusuario y dueño de las tablas**, así que la palabra "inmutable" del historial no está
 * sostenida por nada — cualquier `UPDATE` desde la aplicación, una migración descuidada o una
 * inyección puede reescribir la evidencia que se presentaría en un juicio laboral. A un
 * superusuario no se le revoca nada: se salta la comprobación de permisos entera. Por eso el
 * arreglo no es una línea de SQL, es cambiar con qué credencial se conecta la aplicación.
 *
 * QUÉ HACE ESTE COMANDO
 *  1. **Diagnostica** (siempre, aunque no se aplique nada): con qué rol se conecta la aplicación,
 *     si ese rol es superusuario, quién es el dueño de las tablas, si la función del trigger es
 *     `SECURITY DEFINER`, y qué permisos tiene hoy el rol sobre las tablas custodiadas.
 *  2. **Aplica el candado** de forma idempotente sobre un rol de aplicación que YA EXISTA:
 *     los permisos que sí necesita para operar, y la retirada explícita de los que no.
 *
 * QUÉ NO HACE, A PROPÓSITO
 *  · **No crea el rol ni maneja contraseñas.** La contraseña del rol de la aplicación es una
 *    credencial de producción: la pone una persona, no un comando dentro de un despliegue. Si el
 *    rol no existe, el comando se niega y escribe la línea exacta que hay que ejecutar.
 *  · **No cambia el `.env` ni reinicia nada.** El cambio de credencial es un paso con ventana de
 *    mantenimiento y respaldo probado: va en el runbook (docs/RUNBOOK_CANDADO_BITACORA.md), no
 *    dentro de un script.
 *
 * LAS TRES CERRADURAS, Y POR QUÉ CADA UNA
 *  · `time_entries_historial`: la aplicación queda en **sólo lectura**. Ni siquiera INSERT: el
 *    único camino hacia esa tabla es el trigger, que escribe con los permisos de su dueño porque
 *    la función es `SECURITY DEFINER` (migración 2026_09_05_090000). Sin ese `SECURITY DEFINER`
 *    este candado deja a la plantilla sin poder fichar — está probado y documentado allí.
 *  · `asistencia_correcciones`: **SELECT e INSERT, nunca UPDATE ni DELETE**. Es la póliza que
 *    declara quién corrigió y por qué. Una póliza contable no se borra: se cancela con otra.
 *  · `time_entries`: se retira **TRUNCATE**. El trigger es `FOR EACH ROW`, así que un `TRUNCATE`
 *    vaciaría la asistencia de todas las empresas **sin dejar una sola fila en el historial**.
 *    Era el agujero por el que se colaba el escenario que la bitácora existe para impedir.
 *
 * Es idempotente y está pensado para correr en cada despliegue **después** de `migrate`: una tabla
 * nueva nace sin permisos para el rol de la aplicación, y sin este paso el despliegue siguiente
 * rompería la función que la estrena.
 */
#[Signature('bitacora:candado
    {--rol= : Rol de Postgres de la aplicación al que se le aplican los permisos}
    {--aplicar : Escribe los permisos (sin esto sólo diagnostica)}')]
#[Description('Diagnostica y cierra la bitácora inmutable: deja el historial en sólo lectura para la aplicación. Simulacro por defecto.')]
class CandadoDeLaBitacora extends Command
{
    /**
     * Qué puede hacer la aplicación en cada tabla custodiada. Lo que no está aquí, se retira.
     *
     * @var array<string, list<string>>
     */
    private const PERMITIDO = [
        'time_entries_historial'  => ['SELECT'],
        'asistencia_correcciones' => ['SELECT', 'INSERT'],
        'time_entries'            => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    ];

    /** Los cuatro permisos de tabla que se evalúan; el resto no aplica a este candado. */
    private const TODOS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'];

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->warn('Esta base no es PostgreSQL: el candado de la bitácora no aplica aquí.');
            $this->line('  (La suite de pruebas corre en sqlite, que no tiene roles ni permisos por tabla.)');

            return self::SUCCESS;
        }

        $this->diagnostico();

        $rol = trim((string) $this->option('rol'));
        if ($rol === '') {
            $this->newLine();
            $this->line('Sin --rol no hay nada que aplicar. El runbook explica cómo crear el rol de');
            $this->line('la aplicación y cambiar la credencial: docs/RUNBOOK_CANDADO_BITACORA.md');

            return self::SUCCESS;
        }

        if (! $this->rolExiste($rol)) {
            $this->newLine();
            $this->error("El rol «{$rol}» no existe en esta base.");
            $this->line('  Este comando NO crea roles ni inventa contraseñas: son credenciales de');
            $this->line('  producción y las pone una persona. Ejecute usted, como superusuario:');
            $this->newLine();
            $this->line("      CREATE ROLE {$rol} LOGIN PASSWORD '…una contraseña larga y nueva…';");
            $this->newLine();
            $this->line('  y luego vuelva a ejecutar este comando con --aplicar.');

            return self::FAILURE;
        }

        if ($this->esSuperusuario($rol)) {
            $this->newLine();
            $this->error("El rol «{$rol}» es SUPERUSUARIO: aplicarle permisos no sirve de nada.");
            $this->line('  Un superusuario se salta la comprobación de permisos entera, así que el');
            $this->line('  historial seguiría siendo escribible y el candado sería decorativo.');
            $this->line("  Quítele el atributo primero:  ALTER ROLE {$rol} NOSUPERUSER;");

            return self::FAILURE;
        }

        return $this->aplicar($rol, (bool) $this->option('aplicar'));
    }

    /** Retrato del estado actual: lo que se ve antes de tocar nada. */
    private function diagnostico(): void
    {
        $usuario = (string) DB::selectOne('SELECT current_user AS u')->u;
        $base    = (string) DB::selectOne('SELECT current_database() AS d')->d;
        $superYo = $this->esSuperusuario($usuario);

        $definer = $this->conexionPrivilegiada()->selectOne(
            "SELECT prosecdef FROM pg_proc WHERE proname = 'registrar_historial_time_entries'"
        );

        $this->newLine();
        $this->line('── ESTADO DE LA BITÁCORA ──');
        $this->table(['Qué', 'Ahora'], [
            ['Base de datos', $base],
            ['La aplicación se conecta como', $usuario],
            ['…y ese rol es superusuario', $superYo ? 'SÍ  ← el candado no le aplica' : 'no'],
            ['Dueño de time_entries_historial', $this->duenoDe('time_entries_historial') ?? '(la tabla no existe)'],
            ['Función del trigger es SECURITY DEFINER', $definer === null ? '(no existe)' : ($definer->prosecdef ? 'sí' : 'NO ← el candado tumbaría el fichaje')],
        ]);

        if ($superYo) {
            $this->newLine();
            $this->warn('La aplicación entra como superusuario: HOY el historial es escribible.');
            $this->line('  Mientras esto siga así, «inmutable» describe una intención, no un hecho.');
        }
    }

    /** Calcula y (si se pide) escribe los permisos. Devuelve el código de salida. */
    private function aplicar(string $rol, bool $aplicar): int
    {
        $conexion = $this->conexionPrivilegiada();
        $base   = (string) $conexion->selectOne('SELECT current_database() AS d')->d;
        $dueno  = $this->duenoDe('time_entries_historial') ?? (string) $conexion->selectOne('SELECT current_user AS u')->u;
        $ordenes = [];
        $filas   = [];

        // 1. Lo que el rol necesita para que la aplicación funcione. Idempotente por definición:
        //    volver a conceder un permiso que ya se tiene no cambia nada.
        $ordenes[] = sprintf('GRANT CONNECT ON DATABASE %s TO %s', $this->id($base), $this->id($rol));
        $ordenes[] = sprintf('GRANT USAGE ON SCHEMA public TO %s', $this->id($rol));
        $ordenes[] = sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $this->id($rol));
        $ordenes[] = sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %s', $this->id($rol));

        // 2. Y lo mismo para las tablas que aún no existen: sin esto, la primera migración que
        //    cree una tabla la deja invisible para la aplicación y el despliegue siguiente rompe.
        $ordenes[] = sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %s',
            $this->id($dueno), $this->id($rol)
        );
        $ordenes[] = sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %s',
            $this->id($dueno), $this->id($rol)
        );

        // 3. Las cerraduras. Se calcula la diferencia contra lo permitido para poder ENSEÑAR qué
        //    se va a retirar antes de retirarlo.
        foreach (self::PERMITIDO as $tabla => $permitidos) {
            if ($this->duenoDe($tabla) === null) {
                $filas[] = [$tabla, '(no existe)', '—'];
                continue;
            }

            $sobran = array_values(array_diff(self::TODOS, $permitidos));
            $tieneDeMas = array_values(array_filter(
                $sobran,
                fn (string $p) => $this->tienePermiso($rol, $tabla, $p)
            ));

            $filas[] = [
                $tabla,
                implode(', ', $permitidos),
                $tieneDeMas === [] ? 'ya cerrado' : 'se retira: ' . implode(', ', $tieneDeMas),
            ];

            $ordenes[] = sprintf(
                'REVOKE %s ON %s FROM %s',
                implode(', ', $sobran), $this->id($tabla), $this->id($rol)
            );
        }

        $this->newLine();
        $this->line($aplicar ? "── APLICANDO EL CANDADO SOBRE «{$rol}» ──" : "── SIMULACRO sobre «{$rol}» (nada se escribe; use --aplicar) ──");
        $this->table(['Tabla', 'La aplicación podrá', 'Estado'], $filas);

        if (! $aplicar) {
            $this->newLine();
            $this->line('Órdenes que se ejecutarían:');
            foreach ($ordenes as $sql) {
                $this->line('    ' . $sql . ';');
            }
            $this->newLine();
            $this->info('Nada se escribió. Repita con --aplicar.');

            return self::SUCCESS;
        }

        $conexion->transaction(function () use ($ordenes, $conexion) {
            foreach ($ordenes as $sql) {
                $conexion->statement($sql);
            }
        });

        // 4. Comprobar el resultado en vez de darlo por hecho: se vuelve a preguntar a Postgres.
        $malas = [];
        foreach (self::PERMITIDO as $tabla => $permitidos) {
            if ($this->duenoDe($tabla) === null) {
                continue;
            }
            foreach (array_diff(self::TODOS, $permitidos) as $p) {
                if ($this->tienePermiso($rol, $tabla, $p)) {
                    $malas[] = "{$rol} todavía puede {$p} sobre {$tabla}";
                }
            }
        }

        $this->newLine();
        if ($malas !== []) {
            foreach ($malas as $m) {
                $this->error('✘ ' . $m);
            }
            $this->line('  Suele significar que el permiso viene heredado de otro rol (PUBLIC o un');
            $this->line('  rol del que éste es miembro): revíselo con  \\dp time_entries_historial');

            return self::FAILURE;
        }

        $this->info('✔ Candado puesto: para la aplicación, el historial es de sólo lectura.');

        return self::SUCCESS;
    }

    private function rolExiste(string $rol): bool
    {
        return $this->conexionPrivilegiada()
            ->selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$rol]) !== null;
    }

    private function esSuperusuario(string $rol): bool
    {
        $fila = $this->conexionPrivilegiada()
            ->selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = ?', [$rol]);

        return $fila !== null && (bool) $fila->rolsuper;
    }

    private function duenoDe(string $tabla): ?string
    {
        $fila = $this->conexionPrivilegiada()->selectOne(
            'SELECT tableowner FROM pg_tables WHERE schemaname = ? AND tablename = ?',
            ['public', $tabla]
        );

        return $fila === null ? null : (string) $fila->tableowner;
    }

    private function tienePermiso(string $rol, string $tabla, string $permiso): bool
    {
        return (bool) $this->conexionPrivilegiada()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS p',
            [$rol, $tabla, $permiso]
        )->p;
    }

    /**
     * Los permisos los escribe la credencial de migraciones, no la que atiende peticiones web.
     * En pruebas y entornos antiguos ambas configuraciones coinciden; en ese caso se conserva la
     * conexión actual para no abrir una segunda transacción ni cambiar el comportamiento.
     */
    private function conexionPrivilegiada(): Connection
    {
        $usuarioApp = (string) config('database.connections.pgsql.username');
        $usuarioMigraciones = (string) config('database.connections.pgsql_migraciones.username');

        return $usuarioMigraciones !== '' && $usuarioMigraciones !== $usuarioApp
            ? DB::connection('pgsql_migraciones')
            : DB::connection();
    }

    /**
     * Identificador entrecomillado. Los nombres vienen de la línea de comandos y de la propia
     * base, nunca de un usuario final, pero un identificador no se puede pasar como parámetro
     * enlazado: se entrecomilla y se duplican las comillas internas, que es la regla de Postgres.
     */
    private function id(string $nombre): string
    {
        return '"' . str_replace('"', '""', $nombre) . '"';
    }
}
