<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // §46: el caché de config casi-estática de /sync/state persiste dentro del
        // mismo proceso de PHPUnit (driver 'array'). Se limpia antes de cada test para
        // que un test no vea la config cacheada por otro que reutilizó el mismo tenant_id.
        Cache::flush();
    }

    /**
     * GUARDARRAÍL (2026-08-13): la suite se niega a correr contra una base que no sea de
     * pruebas. Ese día, RefreshDatabase hizo `migrate:fresh` sobre la BD VIVA de la V2:
     * dentro del contenedor, las variables reales de entorno (DB_HOST=db,
     * DB_DATABASE=talent360_v2_saas) le GANARON al `<env force="true">` del
     * phpunit.postgres.xml, y la suite entera corrió contra producción-pruebas. Se recuperó
     * con el respaldo del bloque 0. Para correr la suite Postgres en un contenedor, los
     * DB_* de PRUEBAS deben pasarse como env reales del proceso (docker exec -e ...).
     *
     * Corre en setUpTraits: la app ya arrancó (hay config) y RefreshDatabase aún no toca nada.
     */
    protected function setUpTraits()
    {
        $conexion = config('database.default');
        $base = (string) config("database.connections.{$conexion}.database");

        if ($base !== ':memory:' && !str_contains(strtolower(basename($base)), 'test')) {
            throw new \RuntimeException(
                "La suite SE NIEGA a correr: la conexión '{$conexion}' apunta a '{$base}', que no parece " .
                "una base de PRUEBAS (ni :memory: ni contiene 'test'). Ver el comentario de este guardarraíl."
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Le abre el turno de HOY a un usuario de prueba: un check_in en la fecha del tenant.
     *
     * Desde 2026-08-21 trabajar una tarea exige turno abierto (TaskAssignmentController::update):
     * con el dial en "Acceso Bloqueado" la pestaña de Tareas dejaba iniciar y completar. Toda
     * fixture donde un colaborador mueve sus tareas necesita esto — es como pasa en la vida real.
     */
    protected function conTurnoAbierto(\App\Models\User $user): \App\Models\User
    {
        $tz = \App\Helpers\TenantTimezone::for((int) $user->tenant_id);
        \Illuminate\Support\Facades\DB::table('time_entries')->insert([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'date' => \Carbon\Carbon::now($tz)->toDateString(), 'type' => 'check_in',
            'time' => \Carbon\Carbon::now($tz)->format('H:i:s'), 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
