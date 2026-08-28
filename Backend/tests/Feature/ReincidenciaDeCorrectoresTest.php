<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * También se suma QUIÉN CORRIGE, y quién ficha diferido (2026-08-28, r2b).
 *
 * «El vector más caro no es el empleado quitándose retardos, es un supervisor anulando
 * registros»: la reincidencia de r2-c miraba los fichajes marcados, no los actos de corrección.
 * Y del lado del empleado quedaba un hueco simétrico: sólo la deriva mayor a 10 minutos encendía
 * la bandera, así que quien ficha por la cola offline TODOS los días con deriva chica no
 * aparecía en ningún contador. Ahora la bandeja trae tres agregados: fichajes marcados,
 * diferidos (todo lo que entró por la cola) y correctores, desglosados por tipo de acto porque
 * anular un duplicado, mover una hora e INVENTAR un fichaje no pesan igual.
 */
class ReincidenciaDeCorrectoresTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Correctores QA', 'subdomain' => 'correctoresqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = $this->persona('Admin Que Revisa', 'admin');
    }

    private function persona(string $nombre, string $rol = 'empleado'): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@correctoresqa.test',
            'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);

        return $user;
    }

    private function fichaje(User $user, string $fecha, array $detalles, bool $flagged = false): TimeEntry
    {
        return TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'date' => $fecha, 'type' => 'check_in', 'time' => '09:03:00',
            'employee_name_at_time' => $user->name,
            'flagged_for_review' => $flagged,
            'details' => json_encode($detalles),
        ]);
    }

    private function correccion(User $autor, User $empleado, string $tipo, ?int $timeEntryId = null): void
    {
        DB::table('asistencia_correcciones')->insert([
            'tenant_id' => $this->tenant->id,
            'time_entry_id' => $timeEntryId,
            'tipo' => $tipo,
            'motivo' => 'Motivo escrito de la prueba de reincidencia',
            'autorizado_por' => $autor->id,
            'empleado_user_id' => $empleado->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bandeja(): array
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/admin/clock/flagged-punches')->json();
    }

    // ------------------------------------------------------------ quién corrige

    /** El corrector aparece con su desglose: no es lo mismo limpiar duplicados que fabricar. */
    public function test_la_bandeja_suma_quien_corrige_desglosado_por_tipo(): void
    {
        $supervisor = $this->persona('Supervisor Activo', 'supervisor');
        $maria = $this->persona('Maria');
        $jose = $this->persona('Jose');

        $f1 = $this->fichaje($maria, now()->subDays(5)->toDateString(), []);
        $f2 = $this->fichaje($jose, now()->subDays(3)->toDateString(), []);

        $this->correccion($supervisor, $maria, 'anulacion', $f1->id);
        $this->correccion($supervisor, $jose, 'sustitucion', $f2->id);
        $this->correccion($supervisor, $jose, 'alta');

        $correctores = collect($this->bandeja()['correctores']);
        $this->assertCount(1, $correctores);

        $fila = $correctores->first();
        $this->assertSame('Supervisor Activo', $fila['nombre']);
        $this->assertSame(3, (int) $fila['total']);
        $this->assertSame(1, (int) $fila['anulaciones']);
        $this->assertSame(1, (int) $fila['sustituciones']);
        $this->assertSame(1, (int) $fila['altas'], 'un alta FABRICA un fichaje: se cuenta aparte');
        $this->assertSame(2, (int) $fila['empleados_distintos']);
        $this->assertSame(0, (int) $fila['a_si_mismo']);
    }

    /** LA SEÑAL MÁS GRAVE: quien corrige SU PROPIA asistencia sale marcado aparte. */
    public function test_corregirse_a_si_mismo_se_cuenta_por_separado(): void
    {
        $supervisor = $this->persona('Supervisor Que Se Corrige', 'supervisor');
        $suyo = $this->fichaje($supervisor, now()->subDays(2)->toDateString(), []);

        $this->correccion($supervisor, $supervisor, 'sustitucion', $suyo->id);

        $fila = collect($this->bandeja()['correctores'])->first();
        $this->assertSame(1, (int) $fila['a_si_mismo'], 'corregirse a sí mismo no se diluye en el total');
    }

    /** Un corrector borrado de la plantilla no desaparece del registro (leftJoin, no inner). */
    public function test_si_el_corrector_ya_no_existe_su_rastro_permanece(): void
    {
        $temporal = $this->persona('Supervisor Temporal', 'supervisor');
        $maria = $this->persona('Maria Dos');
        $this->correccion($temporal, $maria, 'anulacion');

        User::where('id', $temporal->id)->forceDelete();

        $correctores = collect($this->bandeja()['correctores']);
        $this->assertCount(1, $correctores, 'la corrección sigue contada aunque su autor ya no esté');
        $this->assertNull($correctores->first()['nombre']);
    }

    /** La ventana también es de 90 días. */
    public function test_las_correcciones_viejas_no_cuentan(): void
    {
        $supervisor = $this->persona('Supervisor Antiguo', 'supervisor');
        $maria = $this->persona('Maria Tres');

        DB::table('asistencia_correcciones')->insert([
            'tenant_id' => $this->tenant->id, 'tipo' => 'anulacion',
            'motivo' => 'Correccion de hace mucho tiempo',
            'autorizado_por' => $supervisor->id, 'empleado_user_id' => $maria->id,
            'created_at' => Carbon::now()->subDays(120), 'updated_at' => Carbon::now()->subDays(120),
        ]);

        $this->assertCount(0, $this->bandeja()['correctores']);
    }

    // ------------------------------------------------------------ quién ficha diferido

    /** El que ficha offline con deriva CHICA no encendía ninguna bandera; ahora se cuenta. */
    public function test_los_diferidos_se_cuentan_aunque_no_esten_marcados(): void
    {
        $listo = $this->persona('Ficha Siempre Offline');

        // Tres días distintos, deriva de 4 minutos: nunca dispara flagged_for_review.
        foreach ([9, 6, 2] as $dias) {
            $this->fichaje($listo, now()->subDays($dias)->toDateString(), [
                'hora_reclamada' => '09:00:00', 'recibido_a' => '09:04:00', 'deriva_min' => 4,
            ]);
        }

        $bandeja = $this->bandeja();

        $this->assertCount(0, $bandeja['reincidencia'], 'ninguno está marcado: el contador viejo no los ve');

        $diferidos = collect($bandeja['diferidos']);
        $this->assertCount(1, $diferidos);
        $this->assertSame('Ficha Siempre Offline', $diferidos->first()['nombre']);
        $this->assertSame(3, (int) $diferidos->first()['veces']);
        $this->assertSame(3, (int) $diferidos->first()['dias']);
    }

    /** Y el ponche en línea no se cuenta como diferido (no lleva el marcador del servidor). */
    public function test_un_fichaje_en_linea_no_cuenta_como_diferido(): void
    {
        $normal = $this->persona('Ficha En Linea');
        $this->fichaje($normal, now()->subDays(1)->toDateString(), ['instante_utc' => '2026-08-27T09:03:00+00:00']);

        $this->assertCount(0, $this->bandeja()['diferidos']);
    }
}
