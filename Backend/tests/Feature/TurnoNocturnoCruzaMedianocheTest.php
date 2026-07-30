<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H21 — turnos que CRUZAN MEDIANOCHE. Test de CARACTERIZACIÓN: fija el comportamiento ACTUAL,
 * que es defectuoso, para que el día que se corrija haya con qué comparar.
 *
 * `processPunch` asigna `date = now->format('Y-m-d')`: el día CALENDARIO en la zona del tenant,
 * sin concepto de "día de negocio". Con un turno 22:00–02:00 eso parte cada jornada en dos:
 *
 *   2026-07-29  check_in   22:00
 *   2026-07-30  check_out  02:00
 *
 * Dos consecuencias, ambas medidas en vivo (ver el doc de hallazgos):
 *
 *  1. La nómina cuenta `attendedDates`, así que UNA noche genera DOS días asistidos: las faltas
 *     de la semana bajaron de 5 a 4 y el neto se duplicó (1 652.78 → 3 305.56) por una sola
 *     jornada.
 *  2. El retardo se mide contra el reloj del día calendario, así que un check-in a las 00:30 con
 *     turno de 22:00 —2h30 tarde— sale PUNTUAL: 30 < 1320.
 *
 * NO se corrige aquí a propósito. El arreglo exige un corte de jornada (si `shiftStart >
 * shiftEnd`, lo anterior a `shiftEnd` pertenece al día previo), y eso cambia la semántica de la
 * fecha de TODOS los fichajes: toca nómina, faltas, flags de turno incompleto, el dial y los
 * reportes. Es decisión de producto, y la operación actual no tiene turnos nocturnos.
 *
 * CUANDO SE CORRIJA: estos tests deben empezar a fallar. Ese es su propósito — invertir las
 * aserciones marcadas y el sistema quedará descrito correctamente.
 */
class TurnoNocturnoCruzaMedianocheTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Nocturna', 'subdomain' => 'noct',
            'plan' => 'enterprise', 'max_users' => 10, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Zona fija para que el test no dependa de dónde corra.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'key' => 'timezone'],
            ['value' => json_encode('America/Mexico_City')]
        );
    }

    private function veladorNocturno(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'base_salary' => 9000,
            'shiftStart' => '22:00:00', 'shiftEnd' => '02:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    /** Ficha en un instante concreto usando la vía offline, que fija el momento real. */
    private function fichaEn(User $user, string $instanteUtc, string $tipo, string $sello): void
    {
        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [[
                'type' => $tipo,
                'client_stamp' => $sello,
                'occurred_at' => $instanteUtc,
            ]],
        ])->assertStatus(200);
    }

    public function test_la_jornada_nocturna_queda_partida_en_dos_dias(): void
    {
        $velador = $this->veladorNocturno();

        // 04:00Z = 22:00 del día 29 en Ciudad de México (UTC-6).
        $this->fichaEn($velador, '2026-07-30T04:00:00Z', 'check_in', 'noct-in');
        // 08:00Z = 02:00 del día 30. Misma jornada, día calendario distinto.
        $this->fichaEn($velador, '2026-07-30T08:00:00Z', 'check_out', 'noct-out');

        $filas = DB::table('time_entries')->where('user_id', $velador->id)
            ->orderBy('date')->get(['date', 'type']);

        $this->assertCount(2, $filas);

        // COMPORTAMIENTO ACTUAL (defectuoso): entrada y salida en días distintos.
        $this->assertSame('2026-07-29', (string) $filas[0]->date);
        $this->assertSame('check_in', $filas[0]->type);
        $this->assertSame('2026-07-30', (string) $filas[1]->date);
        $this->assertSame('check_out', $filas[1]->type);

        // AL CORREGIR: ambas filas deben caer en 2026-07-29 (el día en que EMPEZÓ la jornada).
        // $this->assertSame('2026-07-29', (string) $filas[1]->date);
    }

    public function test_una_sola_noche_genera_dos_dias_asistidos(): void
    {
        $velador = $this->veladorNocturno();

        $this->fichaEn($velador, '2026-07-30T04:00:00Z', 'check_in', 'n2-in');
        $this->fichaEn($velador, '2026-07-30T08:00:00Z', 'check_out', 'n2-out');

        $diasConAsistencia = DB::table('time_entries')
            ->where('user_id', $velador->id)
            ->whereIn('type', ['check_in', 'check_out'])
            ->distinct()->count('date');

        // COMPORTAMIENTO ACTUAL: 2 días asistidos por UNA noche. Como la nómina paga por día
        // (`base/6` sobre `attendedDates`), esa noche se cobra dos veces.
        $this->assertSame(2, $diasConAsistencia);

        // AL CORREGIR: 1.
        // $this->assertSame(1, $diasConAsistencia);
    }

    public function test_el_retardo_de_madrugada_no_se_cobra(): void
    {
        $velador = $this->veladorNocturno();

        // 06:30Z = 00:30 local. Con turno de 22:00, son 2h30 de retardo.
        $this->fichaEn($velador, '2026-07-30T06:30:00Z', 'check_in', 'tarde-in');

        $fila = DB::table('time_entries')->where('user_id', $velador->id)->first();

        $this->assertSame('00:30:00', substr((string) $fila->time, 0, 8));

        // COMPORTAMIENTO ACTUAL: puntual. Se compara 30 min contra 1320 y "llegó temprano".
        $this->assertFalse((bool) $fila->is_late);
        $this->assertSame(0, (int) $fila->late_minutes);

        // AL CORREGIR: 150 minutos de retardo.
        // $this->assertTrue((bool) $fila->is_late);
        // $this->assertSame(150, (int) $fila->late_minutes);
    }

    public function test_un_turno_que_NO_cruza_medianoche_sigue_bien(): void
    {
        // Control: lo que ya funciona debe seguir funcionando cuando se corrija lo anterior.
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'base_salary' => 9000,
            'shiftStart' => '09:00:00', 'shiftEnd' => '18:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $user->fresh();

        // 15:00Z = 09:00 local; 23:00Z = 17:00 local. Mismo día.
        $this->fichaEn($user, '2026-07-30T15:00:00Z', 'check_in', 'dia-in');
        $this->fichaEn($user, '2026-07-30T23:00:00Z', 'check_out', 'dia-out');

        $dias = DB::table('time_entries')->where('user_id', $user->id)->distinct()->pluck('date');

        $this->assertCount(1, $dias, 'Un turno diurno debe quedar en un solo día.');
        $this->assertSame('2026-07-30', (string) $dias[0]);
    }
}
