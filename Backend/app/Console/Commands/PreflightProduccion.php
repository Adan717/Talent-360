<?php

namespace App\Console\Commands;

use App\Support\EstadoDelRespaldo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación previa a operar en PRODUCCIÓN (`php artisan reloj:preflight`).
 *
 * Existe porque varias protecciones del Reloj y de la plataforma dependen de que la app se sepa
 * en producción (`app()->isProduction()`): si el `.env` del servidor quedara en `local`, se
 * reactivarían en silencio el modo de hora simulada (el cliente fija la hora del ponche), la
 * suplantación de usuario en los flujos de apertura y los endpoints de reseteo de QA. Los scripts
 * de despliegue no fijan APP_ENV ni APP_DEBUG, así que conviene comprobarlo tras cada deploy.
 *
 * Salida: código 0 si todo está bien, 1 si hay algún fallo crítico.
 */
class PreflightProduccion extends Command
{
    protected $signature = 'reloj:preflight';
    protected $description = 'Verifica que la configuración sea segura para operar en producción';

    private array $fallos = [];
    private array $avisos = [];
    private bool $baseViva = false;

    public function handle(): int
    {
        $this->info('Verificación previa a producción');
        $this->line('');

        $this->revisarEntorno();
        // El respaldo va antes que la base A PROPÓSITO: no la necesita, y el momento en que
        // más urge saber si hay respaldo es justamente cuando la base no contesta.
        $this->revisarRespaldo();
        $this->revisarBaseDeDatos();

        // (2026-09-05) Estas dos preguntan por tablas. Antes se llamaban siempre y, con la base
        // caída, `Schema::hasTable()` lanzaba una QueryException SIN CAPTURAR: el comando moría
        // con una traza en pantalla —y con las credenciales de la conexión dentro— en vez de
        // decir "no hay conexión a la base de datos", que es lo que ya había detectado la línea
        // anterior. Un verificador que revienta al encontrar el problema no verifica nada.
        if ($this->baseViva) {
            $this->revisarSecretosDeVault();
            $this->revisarZonaHoraria();
        }

        $this->line('');
        foreach ($this->avisos as $a) {
            $this->warn('  AVISO   ' . $a);
        }
        foreach ($this->fallos as $f) {
            $this->error('  FALLO   ' . $f);
        }

        if (empty($this->fallos)) {
            $this->line('');
            $this->info(empty($this->avisos)
                ? 'Todo correcto: la configuración es segura para producción.'
                : 'Sin fallos críticos. Revisa los avisos de arriba.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->error('Hay ' . count($this->fallos) . ' fallo(s) crítico(s). NO operes con datos reales hasta resolverlos.');
        return self::FAILURE;
    }

    private function ok(string $msg): void
    {
        $this->line('  <fg=green>OK</>      ' . $msg);
    }

    private function revisarEntorno(): void
    {
        $env = app()->environment();
        if (app()->isProduction()) {
            $this->ok("APP_ENV = {$env} (los gates de seguridad están activos)");
        } else {
            $this->fallos[] = "APP_ENV = {$env}. En producción DEBE ser 'production': si no, se "
                . 'permite la hora simulada del cliente en los ponches, la suplantación de usuario '
                . 'en apertura de tienda y los endpoints de reseteo de QA.';
        }

        if (config('app.debug')) {
            $this->fallos[] = 'APP_DEBUG = true. Cada error 500 expondría la traza completa, '
                . 'incluidas credenciales de base de datos. Debe ser false.';
        } else {
            $this->ok('APP_DEBUG = false (no se filtran trazas ni credenciales)');
        }

        if (empty(config('app.key'))) {
            $this->fallos[] = 'APP_KEY vacía: sesiones y datos cifrados no son seguros. Ejecuta php artisan key:generate.';
        } else {
            $this->ok('APP_KEY definida');
        }

        if (env('ALLOW_QA_RESET', false)) {
            $this->fallos[] = 'ALLOW_QA_RESET está activo: habilita el borrado de datos de QA en producción.';
        } else {
            $this->ok('ALLOW_QA_RESET desactivado');
        }

        // (2026-08-26) Antes esto leía la variable de entorno con default `false`, mientras la
        // aplicación usaba `config('session.secure')` con default `true`: el preflight reportaba
        // un valor que NO era el que el sistema usaba. Ahora se pregunta lo mismo que la app.
        $cookieSegura = (bool) config('session.secure');
        $porHttps = str_starts_with((string) config('app.url'), 'https://');

        if ($cookieSegura && !$porHttps) {
            $this->avisos[] = 'La cookie de sesión está marcada como SEGURA pero APP_URL es http://. '
                . 'Una cookie segura NO viaja por HTTP: la sesión por cookie no va a funcionar. '
                . 'Pon un certificado (y APP_URL en https) o SESSION_SECURE_COOKIE=false mientras tanto.';
        } elseif (!$cookieSegura && $porHttps) {
            $this->avisos[] = 'El sitio va por HTTPS pero la cookie de sesión NO está marcada como '
                . 'segura: viajaría en claro. Pon SESSION_SECURE_COOKIE=true.';
        } elseif ($cookieSegura) {
            $this->ok('Cookie de sesión segura y el sitio va por HTTPS');
        } else {
            $this->ok('Cookie de sesión sin marca segura, acorde con un sitio por HTTP (pendiente: certificado)');
        }
    }

    private function revisarBaseDeDatos(): void
    {
        try {
            DB::connection()->getPdo();
            $this->baseViva = true;
            $this->ok('Conexión a la base de datos correcta (' . DB::connection()->getDatabaseName() . ')');
        } catch (\Throwable $e) {
            $this->fallos[] = 'No hay conexión a la base de datos: ' . $e->getMessage();
            return;
        }

        try {
            $pendientes = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();
            if ($pendientes > 0) {
                $this->fallos[] = "Hay {$pendientes} migración(es) sin aplicar. Ejecuta php artisan migrate --force.";
            } else {
                $this->ok('Todas las migraciones están aplicadas');
            }
        } catch (\Throwable $e) {
            $this->avisos[] = 'No se pudo comprobar el estado de las migraciones: ' . $e->getMessage();
        }
    }

    private function revisarSecretosDeVault(): void
    {
        if (!Schema::hasTable('tenants') || !Schema::hasColumn('tenants', 'org_vault_passcodes')) {
            return;
        }
        $sinPasscodes = DB::table('tenants')
            ->whereNull('org_vault_passcodes')
            ->where('is_active', true)
            ->count();
        if ($sinPasscodes > 0) {
            $this->avisos[] = "{$sinPasscodes} empresa(s) activas sin passcodes de Wiki configurados. "
                . 'La Wiki pública queda cerrada para ellas hasta que el admin los fije (comportamiento seguro).';
        } else {
            $this->ok('Passcodes de Wiki configurados por empresa');
        }
    }

    private function revisarZonaHoraria(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }
        $tenantsActivos = Schema::hasTable('tenants')
            ? DB::table('tenants')->where('is_active', true)->pluck('id')
            : collect();
        $conTz = DB::table('system_settings')->where('key', 'timezone')->pluck('tenant_id')->filter()->unique();
        $sinTz = $tenantsActivos->diff($conTz);

        if ($sinTz->isNotEmpty()) {
            $this->avisos[] = $sinTz->count() . ' empresa(s) activas sin zona horaria configurada: '
                . 'usarán America/Mexico_City por defecto. Verifica que sea la correcta, porque de la '
                . 'zona dependen los retardos y el corte del día en nómina.';
        } else {
            $this->ok('Zona horaria configurada por empresa');
        }
    }

    /**
     * ¿Hay de dónde revivir esto si se muere? Se pregunta con la MISMA clase que contesta
     * /api/health, para que el preflight y el vigilante externo no puedan discrepar.
     *
     * Por qué la marca ausente es AVISO aquí y en cambio es 503 en el endpoint: son dos
     * preguntas distintas. El endpoint pregunta "¿está sano AHORA?" y no poder confirmar el
     * respaldo es no estar sano. El preflight se corre justo después de desplegar, cuando lo
     * normal es que el primer respaldo todavía no haya pasado; hacerlo fallar ahí obligaría a
     * ignorar el preflight, y un preflight que se ignora no protege nada. Un respaldo VIEJO sí
     * es fallo: ese ya no es "todavía no", es "dejó de correr".
     */
    private function revisarRespaldo(): void
    {
        $r = EstadoDelRespaldo::revisar();

        if ($r['ok']) {
            $this->ok(sprintf(
                'Respaldo de hace %.1f h (%s), dentro de las %d h de margen',
                $r['horas'],
                $r['ultimo_utc'],
                EstadoDelRespaldo::HORAS_MAXIMAS
            ));
            return;
        }

        if ($r['motivo'] === 'viejo') {
            $this->fallos[] = sprintf(
                'El último respaldo es de hace %.1f h (%s) y el máximo son %d h. El cron de '
                . '/usr/local/bin/respaldo-talent360 dejó de correr o está fallando: revisa '
                . '/var/log/talent360-respaldo.log. Sin respaldo, un disco perdido es la nómina perdida.',
                $r['horas'],
                $r['ultimo_utc'],
                EstadoDelRespaldo::HORAS_MAXIMAS
            );
            return;
        }

        $porque = $r['motivo'] === 'ilegible'
            ? 'existe pero no se puede leer (revisa permisos: la escribe root, la lee www-data) o no es JSON válido'
            : 'no existe todavía';

        $this->avisos[] = 'No se puede confirmar ningún respaldo: la marca ' . EstadoDelRespaldo::ruta()
            . ' ' . $porque . '. Si el servidor es nuevo, corre a mano '
            . '/usr/local/bin/respaldo-talent360 una vez; si no lo es, el respaldo lleva sin '
            . 'correr al menos desde el último despliegue. Ver docs/VIGILANTE_DEL_SERVIDOR.md.';
    }
}
