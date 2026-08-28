<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R84 (Fase 2 / T2.1 del plan v2): Offline-first robusto — batch endpoint.
 *
 * La cola offline (offlineDb.ts, R29) sincronizaba 1×1 vía /clock/punch: (a) sin atomicidad (un fallo
 * a mitad dejaba medio lote sincronizado), (b) sin idempotencia (un re-envío duplicaba), y (c) el
 * `time` del cliente se IGNORABA en producción → un ponche offline registraba la hora de SYNC, no la
 * real (se perdía incluso la FECHA original).
 *
 * `POST /clock/punch-batch` procesa el lote en UNA `DB::transaction` (inmutabilidad histórica), es
 * idempotente por `client_stamp` (re-enviar no duplica), y registra cada ponche en su MOMENTO offline
 * real (`occurred_at`). Un ponche inválido se rechaza sin tumbar el resto (savepoints por-ponche —
 * lección R51: en Postgres una excepción aborta la tx completa si no hay savepoint).
 */
class PunchBatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(string $role = 'empleado'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Batch', 'subdomain' => 'batch' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab Batch',
            'email' => 'batch' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => $role,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab Batch',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        LftSetting::create(['tenant_id' => $tenant->id, 'late_tolerance_minutes' => 10]);
        return [$tenant, $user];
    }

    private function punch(array $overrides = []): array
    {
        return array_merge([
            'client_stamp' => 'stamp-' . uniqid(),
            'type' => 'check_in',
            'occurred_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'details' => ['note' => 'offline', 'offline' => true],
        ], $overrides);
    }

    // ---- Lo básico: batch + atomicidad ------------------------------------------------

    public function test_batch_registra_varios_ponches(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                $this->punch(['type' => 'check_in', 'occurred_at' => Carbon::now()->subHours(2)->toIso8601String()]),
                $this->punch(['type' => 'check_out', 'occurred_at' => Carbon::now()->subHour()->toIso8601String()]),
            ],
        ]);

        $res->assertStatus(200);
        $this->assertSame(2, DB::table('time_entries')->where('user_id', $user->id)->count());
        $results = collect($res->json('results'));
        $this->assertSame(2, $results->where('status', 'recorded')->count());
    }

    public function test_batch_es_idempotente_por_stamp(): void
    {
        [, $user] = $this->makeSetup();
        $stamp = 'stamp-fijo-123';
        $payload = ['punches' => [$this->punch(['client_stamp' => $stamp])]];

        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', $payload)->assertStatus(200);
        // Re-envío del MISMO lote (el cliente reintenta): no duplica.
        $res2 = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', $payload);

        $res2->assertStatus(200);
        $this->assertSame(1, DB::table('time_entries')->where('user_id', $user->id)->count());
        $this->assertSame('duplicate', $res2->json('results.0.status'));
    }

    public function test_batch_registra_el_stamp_en_la_fila(): void
    {
        [, $user] = $this->makeSetup();
        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['client_stamp' => 'stamp-abc'])],
        ])->assertStatus(200);

        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'client_stamp' => 'stamp-abc']);
    }

    // ---- Inmutabilidad histórica: el momento offline REAL -----------------------------

    public function test_batch_preserva_el_momento_offline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00')); // "ahora" = la hora de SYNC
        try {
            [, $user] = $this->makeSetup();
            // El ponche OCURRIÓ ayer 09:05 (offline); se sincroniza hoy 18:00.
            $ocurrio = Carbon::parse('2026-07-14 09:05:00');
            $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
                'punches' => [$this->punch(['type' => 'check_in', 'occurred_at' => $ocurrio->toIso8601String()])],
            ])->assertStatus(200);

            $entry = DB::table('time_entries')->where('user_id', $user->id)->first();
            // Se registra en su MOMENTO real (14 jul 09:05), NO en la hora de sync (15 jul 18:00).
            $this->assertSame('2026-07-14', $entry->date);
            $this->assertSame('09:05:00', $entry->time);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_batch_rechaza_ponche_con_fecha_futura(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['occurred_at' => Carbon::now()->addHours(3)->toIso8601String()])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    public function test_batch_rechaza_ponche_demasiado_viejo(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['occurred_at' => Carbon::now()->subDays(45)->toIso8601String()])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    // ---- Un ponche malo NO tumba el lote (savepoints, lección R51) ---------------------

    public function test_un_ponche_rechazado_no_tumba_los_validos(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                $this->punch(['type' => 'check_in', 'occurred_at' => Carbon::now()->subHours(2)->toIso8601String()]),
                $this->punch(['occurred_at' => Carbon::now()->addDays(1)->toIso8601String()]), // futuro → rechazado
                $this->punch(['type' => 'check_out', 'occurred_at' => Carbon::now()->subHour()->toIso8601String()]),
            ],
        ]);

        $res->assertStatus(200);
        $results = collect($res->json('results'));
        $this->assertSame(2, $results->where('status', 'recorded')->count());
        $this->assertSame(1, $results->where('status', 'rejected')->count());
        // Los 2 válidos SÍ se registraron pese al del medio rechazado.
        $this->assertSame(2, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    // ---- Seguridad / validación -------------------------------------------------------

    public function test_batch_no_registra_ponche_de_otro_tenant(): void
    {
        [, $user] = $this->makeSetup();
        [, $otroUser] = $this->makeSetup();

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['user_id' => $otroUser->id])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $otroUser->id)->count());
    }

    /** B1 (review R84): sólo se sincronizan los ponches del PROPIO emisor, ni siquiera para un colega
     *  del MISMO tenant — si no, el batch sería "fichar por otro" a escala. */
    public function test_batch_solo_registra_ponches_del_propio_usuario(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $colega = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colega', 'email' => 'colega' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['user_id' => $colega->id])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $colega->id)->count());
    }

    /**
     * Sin credencial no hay ponche — pero el rechazo es POR ÍTEM, no del lote (2026-08-28 r2b).
     *
     * Esta prueba exigía 422 (el lote entero muerto), que era justo el defecto: un solo ponche
     * legado sin firma congelaba la cola offline para siempre y los ponches buenos vencian a los
     * 7 dias sin llegar nunca. La regla que ya predicaba la prueba de abajo para el `type`
     * invalido ("un item corrupto no debe volverse pildora venenosa") ahora tambien rige aqui.
     */
    public function test_batch_stamp_requerido_rechaza_el_item_no_el_lote(): void
    {
        [, $user] = $this->makeSetup();
        $sinStamp = $this->punch();
        unset($sinStamp['client_stamp']);
        $bueno = $this->punch(['client_stamp' => 'stamp-del-bueno-r2b']);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$sinStamp, $bueno],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame('missing_stamp', $res->json('results.0.reason'));
        $this->assertSame('recorded', $res->json('results.1.status'), 'el ponche legitimo del mismo lote si entra');
    }

    /**
     * Un tipo inválido se rechaza POR ÍTEM (no un 422 de todo el lote): un ítem legacy/corrupto no
     * debe volverse una píldora venenosa que atasca la cola (review R84).
     */
    public function test_batch_tipo_invalido_se_rechaza_por_item_no_tumba_el_lote(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                $this->punch(['type' => 'zombis']),
                $this->punch(['type' => 'check_in', 'occurred_at' => Carbon::now()->subHour()->toIso8601String()]),
            ],
        ]);

        $res->assertStatus(200);
        $results = collect($res->json('results'));
        $this->assertSame(1, $results->where('status', 'rejected')->count());
        $this->assertSame(1, $results->where('status', 'recorded')->count());
    }

    public function test_batch_occurred_at_invalido_se_rechaza_por_item(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch(['occurred_at' => 'no-es-fecha'])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
    }

    /**
     * Aislamiento con rechazo POST-SQL (lección R51 / probe del review): un feriado bloqueante hace
     * que processPunch lea settings + consulte lft_holidays y ENTONCES lance — el savepoint revierte
     * sólo ese ponche y el siguiente (otro día, sin feriado) se graba igual. (En Postgres esto exige
     * ROLLBACK TO SAVEPOINT para recuperar la tx abortada; verificado en vivo además del suite.)
     */
    public function test_batch_aisla_un_rechazo_despues_de_sql(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            DB::table('lft_holidays')->insert([
                'tenant_id' => $tenant->id, 'date' => '2026-07-14', 'name' => 'Feriado',
                'block_app' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
                'punches' => [
                    // Feriado bloqueante → rechazado DESPUÉS de correr SQL en processPunch.
                    $this->punch(['type' => 'check_in', 'occurred_at' => '2026-07-14T09:00:00Z']),
                    // Otro día, sin feriado → se graba pese al rechazo anterior.
                    $this->punch(['type' => 'check_in', 'occurred_at' => '2026-07-15T09:00:00Z']),
                ],
            ]);

            $res->assertStatus(200);
            $results = collect($res->json('results'));
            $this->assertSame(1, $results->where('status', 'rejected')->count());
            $this->assertSame(1, $results->where('status', 'recorded')->count());
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'date' => '2026-07-15', 'type' => 'check_in']);
            $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->where('date', '2026-07-14')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_batch_vacio_422(): void
    {
        [, $user] = $this->makeSetup();
        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', ['punches' => []])->assertStatus(422);
    }

    public function test_batch_excede_el_tope_422(): void
    {
        [, $user] = $this->makeSetup();
        $punches = [];
        for ($i = 0; $i < 205; $i++) {
            $punches[] = $this->punch();
        }
        $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', ['punches' => $punches])->assertStatus(422);
    }

    // ---- R85: firma offline_stamp (integridad / binding de contenido) -----------------

    public function test_firma_valida_se_registra(): void
    {
        [, $user] = $this->makeSetup();
        $occ = Carbon::now()->subHour()->toIso8601String();
        $gps = ['latitude' => 19.4326, 'longitude' => -99.1332];
        $stamp = \App\Http\Controllers\PunchBatchController::stampFor($user->id, 'check_out', $occ, $gps);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [[
                'client_stamp' => $stamp, 'type' => 'check_out', 'occurred_at' => $occ,
                'details' => ['offline' => true, 'gps' => $gps],
            ]],
        ]);

        $res->assertStatus(200);
        $this->assertSame('recorded', $res->json('results.0.status'));
    }

    /** Firma con formato de hash (64 hex) que NO corresponde al contenido → rechazada (corrupción/
     *  campo alterado sin recomputar). */
    public function test_firma_hash_que_no_corresponde_se_rechaza(): void
    {
        [, $user] = $this->makeSetup();
        $occ = Carbon::now()->subHour()->toIso8601String();
        // Firma computada para OTRO tipo (check_in) pero el ponche dice check_out → no casa.
        $stampMal = \App\Http\Controllers\PunchBatchController::stampFor($user->id, 'check_in', $occ, null);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [[
                'client_stamp' => $stampMal, 'type' => 'check_out', 'occurred_at' => $occ,
                'details' => ['offline' => true],
            ]],
        ]);

        $res->assertStatus(200);
        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    /** Un stamp que NO tiene formato de hash (legacy/fallback pre-R85) se acepta sin verificar
     *  (degradación elegante: sigue sirviendo de llave de idempotencia). */
    public function test_stamp_no_hash_se_acepta_sin_verificar(): void
    {
        [, $user] = $this->makeSetup();
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [$this->punch([
                'client_stamp' => 'legacy-26-1234567890-check_out',
                'type' => 'check_out',
                'occurred_at' => Carbon::now()->subHour()->toIso8601String(),
            ])],
        ]);

        $res->assertStatus(200);
        $this->assertSame('recorded', $res->json('results.0.status'));
    }

    /** El guard de check_in duplicado (R63) sigue aplicando dentro del lote. */
    public function test_batch_respeta_el_guard_de_checkin_duplicado(): void
    {
        // Hora FIJADA (mediodía): el guard R63 dedup por FECHA, y sin pinnear la hora los `now-2h`/`now-1h`
        // cruzaban la medianoche en las ~2h posteriores → dos fechas distintas → 2 filas (flakiness real
        // de reloj, cazada al correr la suite de madrugada). Los demás tests del archivo ya la fijan.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            [, $user] = $this->makeSetup();
            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
                'punches' => [
                    $this->punch(['type' => 'check_in', 'occurred_at' => Carbon::now()->subHours(2)->toIso8601String()]),
                    // 2º check_in con turno ABIERTO → duplicado idempotente (R63), no crea fila.
                    $this->punch(['type' => 'check_in', 'occurred_at' => Carbon::now()->subHour()->toIso8601String()]),
                ],
            ]);

            $res->assertStatus(200);
            $this->assertSame(1, DB::table('time_entries')->where('user_id', $user->id)->where('type', 'check_in')->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
