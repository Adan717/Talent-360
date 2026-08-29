<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorreccionDeAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anular un fichaje marcado NO lo saca del contador (2026-08-28, revisión externa r2c).
 *
 * La bandeja heredaba `ExcludeAnuladasScope`, así que una anulación borraba la marca del patrón.
 * El efecto era el contrario del que busca un tablero de auditoría: quien tiene permiso de
 * corregir podía limpiar su propio rastro, y el tablero quedaba auditable **sólo para quien NO
 * puede corregir**. Ahora lo anulado sigue contando, en su propia categoría visible.
 *
 * Estas pruebas son el candado de la regresión: sin ellas, volver a poner el scope pasaría la
 * suite entera en verde (ninguna prueba de la bandeja creaba un fichaje anulado).
 */
class AnularNoBorraElPatronTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Patron QA', 'subdomain' => 'patronqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa Que Corrige', 'email' => 'jefa@patronqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@patronqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);
    }

    private function fichajeMarcado(string $fecha, string $hora = '09:45:00'): TimeEntry
    {
        return TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => $fecha, 'type' => 'check_in', 'time' => $hora,
            'employee_name_at_time' => 'Colaborador',
            'flagged_for_review' => true,
            'details' => json_encode(['deriva_min' => 45, 'hora_reclamada' => '09:00:00']),
        ]);
    }

    private function bandeja(): array
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/admin/clock/flagged-punches')->json();
    }

    private function filaDe(array $bandeja, string $clave): ?array
    {
        foreach ($bandeja[$clave] ?? [] as $fila) {
            if ((int) $fila['user_id'] === $this->colaborador->id) {
                return $fila;
            }
        }

        return null;
    }

    /** EL CASO DEL ENCARGO: se anula la marca y el contador NO baja; se mueve a "anulados". */
    public function test_anular_un_fichaje_marcado_no_baja_el_contador(): void
    {
        $uno = $this->fichajeMarcado(now()->subDays(5)->toDateString());
        $this->fichajeMarcado(now()->subDays(2)->toDateString());

        $antes = $this->filaDe($this->bandeja(), 'reincidencia');
        $this->assertSame(2, (int) $antes['veces']);
        $this->assertSame(0, (int) $antes['anulados']);

        app(CorreccionDeAsistencia::class)->corregir(
            $uno,
            [],
            'Se retira este fichaje de la bandeja para la prueba del patron.',
            $this->admin
        );

        $despues = $this->filaDe($this->bandeja(), 'reincidencia');

        $this->assertNotNull($despues, 'anular no puede hacer desaparecer a la persona del tablero');
        $this->assertSame(2, (int) $despues['veces'], 'el total NO baja al anular');
        $this->assertSame(1, (int) $despues['anulados'], 'la marca retirada pasa a su propia categoría');
        $this->assertSame(1, (int) $despues['vigentes']);
    }

    /** Y con UNA sola marca, anularla tampoco la esconde: la persona sigue en el tablero. */
    public function test_anular_la_unica_marca_no_vacia_el_tablero(): void
    {
        $solo = $this->fichajeMarcado(now()->subDays(3)->toDateString());

        app(CorreccionDeAsistencia::class)->corregir(
            $solo,
            [],
            'Anulacion de la unica marca: antes desaparecia del contador.',
            $this->admin
        );

        $fila = $this->filaDe($this->bandeja(), 'reincidencia');

        $this->assertNotNull($fila, 'con el scope puesto, esta fila desaparecía y el patrón se perdía');
        $this->assertSame(1, (int) $fila['veces']);
        $this->assertSame(1, (int) $fila['anulados']);
        $this->assertSame(0, (int) $fila['vigentes']);
    }

    /**
     * Una SUSTITUCIÓN no debe inflar el contador: el sustituto hereda `flagged_for_review` y
     * `details` del original, así que contar filas a secas convertiría UN hecho corregido en DOS
     * — el propio arreglo fabricaría reincidencia que no ocurrió.
     */
    public function test_una_sustitucion_no_cuenta_dos_veces(): void
    {
        $original = $this->fichajeMarcado(now()->subDays(4)->toDateString(), '09:45:00');

        app(CorreccionDeAsistencia::class)->corregir(
            $original,
            ['time' => '09:00:00'],
            'La camara lo muestra entrando a las 09:00, el reloj iba adelantado.',
            $this->admin
        );

        $fila = $this->filaDe($this->bandeja(), 'reincidencia');

        $this->assertSame(1, (int) $fila['veces'], 'un hecho corregido sigue siendo UN hecho');
        $this->assertSame(1, (int) $fila['anulados'], 'y queda visible que se corrigió');
        $this->assertSame(1, (int) $fila['dias']);
    }

    /** Lo mismo para los diferidos: anular tampoco limpia el rastro de fichar sin red. */
    public function test_anular_tampoco_limpia_el_contador_de_diferidos(): void
    {
        $uno = $this->fichajeMarcado(now()->subDays(6)->toDateString());

        app(CorreccionDeAsistencia::class)->corregir(
            $uno,
            [],
            'Anulacion para comprobar que el diferido sigue contando.',
            $this->admin
        );

        $fila = $this->filaDe($this->bandeja(), 'diferidos');

        $this->assertNotNull($fila);
        $this->assertSame(1, (int) $fila['anulados']);
    }

    /** La lista accionable SÍ sigue mostrando sólo lo vigente: es la bandeja, no el expediente. */
    public function test_la_lista_de_trabajo_sigue_mostrando_solo_lo_vigente(): void
    {
        $uno = $this->fichajeMarcado(now()->subDays(1)->toDateString());

        app(CorreccionDeAsistencia::class)->corregir(
            $uno,
            [],
            'Resuelto: el fichaje era duplicado y se retira de la bandeja.',
            $this->admin
        );

        $bandeja = $this->bandeja();

        $this->assertSame(0, $bandeja['count'], 'lo anulado sale de la lista de pendientes…');
        $this->assertNotNull($this->filaDe($bandeja, 'reincidencia'), '…pero no del patrón');
    }
}
