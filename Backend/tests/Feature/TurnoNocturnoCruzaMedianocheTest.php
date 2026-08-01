<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H21 — turnos que CRUZAN MEDIANOCHE, ya corregidos con el corte de jornada de
 * `App\Support\JornadaLaboral`. Nació como test de caracterización del defecto; estas
 * aserciones son las que entonces estaban comentadas.
 *
 * ANTES, `processPunch` asignaba `date = now->format('Y-m-d')`: el día CALENDARIO, sin concepto
 * de "día de negocio". Con un turno 22:00–02:00 eso partía cada jornada en dos:
 *
 *   2026-07-29  check_in   22:00
 *   2026-07-30  check_out  02:00
 *
 * Dos daños, ambos medidos en vivo (ver el doc de hallazgos):
 *
 *  1. La nómina cuenta `attendedDates`, así que UNA noche generaba DOS días asistidos: las faltas
 *     de la semana bajaban de 5 a 4 y el neto se duplicaba (1 652.78 → 3 305.56) por una sola
 *     jornada.
 *  2. El retardo se medía contra el reloj del día calendario, así que un check-in a las 00:30 con
 *     turno de 22:00 —2h30 tarde— salía PUNTUAL: 30 < 1320.
 *
 * El arreglo ancla el fichaje al día en que EMPEZÓ la jornada. Como `processPunch` construye la
 * hora esperada sobre `$date`, corregir la fecha corrigió el retardo de paso — un solo cambio.
 * El último caso es el control que protege a los turnos diurnos, que son la inmensa mayoría y no
 * deben notar nada.
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

    public function test_la_jornada_nocturna_queda_en_un_solo_dia(): void
    {
        $velador = $this->veladorNocturno();

        // 04:00Z = 22:00 del día 29 en Ciudad de México (UTC-6).
        $this->fichaEn($velador, '2026-07-30T04:00:00Z', 'check_in', 'noct-in');
        // 08:00Z = 02:00 del día 30. Misma jornada, día calendario distinto.
        $this->fichaEn($velador, '2026-07-30T08:00:00Z', 'check_out', 'noct-out');

        $filas = DB::table('time_entries')->where('user_id', $velador->id)
            ->orderBy('date')->get(['date', 'type']);

        $this->assertCount(2, $filas);

        // Ambos extremos pertenecen a la jornada que EMPEZÓ el 29.
        $this->assertSame('2026-07-29', (string) $filas[0]->date);
        $this->assertSame('check_in', $filas[0]->type);
        $this->assertSame('2026-07-29', (string) $filas[1]->date,
            'La salida de madrugada cierra la jornada de ayer, no abre la de hoy.');
        $this->assertSame('check_out', $filas[1]->type);
    }

    public function test_una_sola_noche_cuenta_como_un_dia_asistido(): void
    {
        $velador = $this->veladorNocturno();

        $this->fichaEn($velador, '2026-07-30T04:00:00Z', 'check_in', 'n2-in');
        $this->fichaEn($velador, '2026-07-30T08:00:00Z', 'check_out', 'n2-out');

        $diasConAsistencia = DB::table('time_entries')
            ->where('user_id', $velador->id)
            ->whereIn('type', ['check_in', 'check_out'])
            ->distinct()->count('date');

        // UNA noche, UN día asistido. Antes eran dos, y como la nómina paga por día
        // (`base/6` sobre `attendedDates`), esa noche se cobraba dos veces.
        $this->assertSame(1, $diasConAsistencia);
    }

    public function test_el_retardo_de_madrugada_si_se_cobra(): void
    {
        $velador = $this->veladorNocturno();

        // 06:30Z = 00:30 local. Con turno de 22:00, son 2h30 de retardo.
        $this->fichaEn($velador, '2026-07-30T06:30:00Z', 'check_in', 'tarde-in');

        $fila = DB::table('time_entries')->where('user_id', $velador->id)->first();

        $this->assertSame('00:30:00', substr((string) $fila->time, 0, 8));

        // 2h30 de retardo. Antes salía puntual: se comparaba 30 min contra 1320 y el sistema
        // concluía que había llegado temprano.
        $this->assertTrue((bool) $fila->is_late);
        $this->assertSame(150, (int) $fila->late_minutes);
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
