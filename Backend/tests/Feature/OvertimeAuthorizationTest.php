<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R81 (Fase 1 / T1.2 del plan v2): Laborar Horas Extras / Feriado REAL.
 *
 * El teatro que se cierra: el botón "Laborar Horas Extras" pedía QR/PIN de supervisor pero (a) en
 * plan free el PIN no se validaba contra NADA (length >= 4 y listo), (b) el "desbloqueo" vivía sólo
 * en un useState del cliente, y (c) el bloqueo de feriado de ClockService rechazaba el check_in
 * IGUAL aunque el supervisor hubiera autorizado. Spec §5: el botón "Desbloquea el dial para trabajar
 * en día libre/feriado".
 *
 * Ahora `POST /clock/authorize-overtime` valida la credencial DEL SUPERVISOR server-side (QR
 * dinámico de 60s, o su PIN de kiosko R54 vía blind index) y registra la autorización en
 * `overtime_authorizations`; el bloqueo de feriado de processPunch se levanta si existe una
 * autorización para (tenant, user, HOY). Nadie se auto-autoriza (R75).
 */
class OvertimeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Tenant + empleado raso (shift 09:00) + feriado bloqueante HOY (2026-07-10). */
    private function makeSetup(bool $blockApp = true): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa OT',
            'subdomain' => 'ot' . uniqid(),
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador Feriado',
            'email' => 'ot' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador Feriado',
            'base_salary' => 3000.00,
            'shiftStart' => '09:00:00',
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        DB::table('lft_holidays')->insert([
            'tenant_id' => $tenant->id, 'date' => '2026-07-10',
            'name' => 'Feriado de Prueba', 'block_app' => $blockApp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$tenant, $user];
    }

    /** Supervisor del tenant, con expediente y (opcional) PIN de kiosko. */
    private function makeSupervisor(Tenant $tenant, ?string $kioskPin = null, string $role = 'supervisor'): User
    {
        $sup = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supervisor ' . uniqid(),
            'email' => 'sup' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
        $emp = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $sup->id,
            'name' => $sup->name,
            'base_salary' => 5000.00,
            'shiftStart' => '09:00:00',
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        if ($kioskPin !== null) {
            $emp->setKioskPin($kioskPin);
            $emp->save();
        }
        return $sup;
    }

    /** QR dinámico vivo (60s) del supervisor dado. */
    private function makeQrToken(User $supervisor, array $overrides = []): string
    {
        $token = 'qr_' . bin2hex(random_bytes(16));
        DB::table('supervisor_qr_tokens')->insert(array_merge([
            'supervisor_id' => $supervisor->id,
            'token' => $token,
            'expires_at' => now()->addSeconds(60),
            'is_used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
        return $token;
    }

    private function checkInBloqueado(User $user): bool
    {
        try {
            app(ClockService::class)->processPunch($user, 'check_in');
            return false;
        } catch (\Exception $e) {
            return true;
        }
    }

    // ---- El bloqueo base ---------------------------------------------------------------

    public function test_feriado_bloqueante_rechaza_el_check_in_sin_autorizacion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [, $user] = $this->makeSetup();
            $this->assertTrue($this->checkInBloqueado($user), 'el feriado bloqueante debe rechazar el check_in');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_feriado_no_bloqueante_no_requiere_autorizacion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [, $user] = $this->makeSetup(false);
            app(ClockService::class)->processPunch($user, 'check_in');
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- La autorización por QR --------------------------------------------------------

    public function test_qr_de_supervisor_autoriza_y_el_check_in_pasa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);
            $token = $this->makeQrToken($sup);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $token]);

            $res->assertStatus(201);
            $this->assertDatabaseHas('overtime_authorizations', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10',
                'kind' => 'holiday', 'authorized_by' => $sup->id, 'method' => 'qr',
            ]);

            // El MISMO check_in que antes se bloqueaba ahora pasa.
            app(ClockService::class)->processPunch($user, 'check_in');
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);

            // El token quedó consumido (un solo uso).
            $this->assertTrue((bool) DB::table('supervisor_qr_tokens')->where('token', $token)->value('is_used'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_qr_de_supervisor_de_otro_tenant_no_autoriza(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [, $user] = $this->makeSetup();
            [$tenantB, ] = $this->makeSetup();
            $supB = $this->makeSupervisor($tenantB);
            $token = $this->makeQrToken($supB);

            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $token])
                ->assertStatus(403);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
            $this->assertTrue($this->checkInBloqueado($user));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_qr_expirado_o_usado_no_autoriza(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);

            $expirado = $this->makeQrToken($sup, ['expires_at' => now()->subSecond()]);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $expirado])
                ->assertStatus(422);

            $usado = $this->makeQrToken($sup, ['is_used' => true]);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $usado])
                ->assertStatus(422);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_qr_de_un_empleado_sin_autoridad_no_autoriza(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            // El dueño del QR es un empleado RASO del mismo tenant (el eje de ACCESO manda, R57).
            $raso = $this->makeSupervisor($tenant, null, 'empleado');
            $token = $this->makeQrToken($raso);

            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $token])
                ->assertStatus(403);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** R75: nadie se auto-autoriza — el QR debe ser de OTRA persona con autoridad. */
    public function test_nadie_se_autoautoriza_con_su_propio_qr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, ] = $this->makeSetup();
            // El que quiere laborar ES un supervisor; genera SU PROPIO QR y se lo aplica.
            $supTrabajador = $this->makeSupervisor($tenant);
            $token = $this->makeQrToken($supTrabajador);

            $this->actingAs($supTrabajador)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $token])
                ->assertStatus(403);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $supTrabajador->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- La autorización por PIN (plan free — antes NO se validaba contra nada) --------

    public function test_pin_de_supervisor_autoriza_y_el_check_in_pasa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant, '935710');

            $res = $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '935710']);

            $res->assertStatus(201);
            $this->assertDatabaseHas('overtime_authorizations', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10',
                'kind' => 'holiday', 'authorized_by' => $sup->id, 'method' => 'pin',
            ]);

            app(ClockService::class)->processPunch($user, 'check_in');
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pin_de_empleado_raso_no_autoriza(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            // PIN válido de kiosko, pero de un empleado SIN autoridad.
            $this->makeSupervisor($tenant, '935710', 'empleado');

            // 422 genérico (no 403 "no es supervisor"): no se revela que el PIN existe (anti-oráculo).
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '935710'])
                ->assertStatus(422);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
            $this->assertTrue($this->checkInBloqueado($user));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pin_incorrecto_no_autoriza_y_los_fallos_se_rate_limitan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $this->makeSupervisor($tenant, '935710');

            // 5 intentos fallidos consumen el presupuesto…
            for ($i = 0; $i < 5; $i++) {
                $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '111213'])
                    ->assertStatus(422);
            }
            // …el 6º (aunque fuera correcto) rebota por rate-limit: la defensa real del PIN online.
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '935710'])
                ->assertStatus(429);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- Alcance de la autorización ----------------------------------------------------

    public function test_la_autorizacion_de_otro_dia_no_levanta_el_bloqueo_de_hoy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);
            DB::table('overtime_authorizations')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-09',
                'kind' => 'holiday', 'authorized_by' => $sup->id, 'method' => 'qr',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->assertTrue($this->checkInBloqueado($user), 'la autorización de AYER no vale para hoy');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_doble_autorizacion_el_mismo_dia_no_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);

            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)])
                ->assertStatus(201);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)])
                ->assertStatus(201);

            $this->assertSame(1, DB::table('overtime_authorizations')
                ->where('user_id', $user->id)->where('date', '2026-07-10')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Sin feriado: la autorización se registra como 'rest_day' (día de descanso laborado). */
    public function test_sin_feriado_la_autorizacion_es_de_dia_de_descanso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00')); // día SIN feriado en la fixture
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);

            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)])
                ->assertStatus(201);

            $this->assertDatabaseHas('overtime_authorizations', [
                'user_id' => $user->id, 'date' => '2026-07-11', 'kind' => 'rest_day',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sin_credencial_es_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [, $user] = $this->makeSetup();
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', [])->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- Fixes del review R81 ----------------------------------------------------------

    /** Un supervisor ARCHIVADO (is_active=false) no autoriza — su PIN/QR quedan muertos. */
    public function test_supervisor_archivado_no_autoriza(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant, '935710');
            DB::table('users')->where('id', $sup->id)->update(['is_active' => false]);
            $token = $this->makeQrToken($sup);

            // PIN → 422 genérico (mismo mensaje que "no reconocido", sin oráculo).
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '935710'])
                ->assertStatus(422);
            // QR → 403 (el token no es adivinable, se puede ser específico).
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $token])
                ->assertStatus(403);

            $this->assertSame(0, DB::table('overtime_authorizations')->where('user_id', $user->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * El oráculo de PIN cazado por el review: un PIN CORRECTO de un empleado SIN autoridad debe dar
     * el MISMO 422 genérico que un PIN inexistente (no revelar que es un PIN real) y CONSUMIR
     * presupuesto de rate-limit (no dar adivinanzas gratis).
     */
    public function test_pin_correcto_sin_autoridad_no_es_un_oraculo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $this->makeSupervisor($tenant, '935710', 'empleado'); // PIN válido, sin autoridad

            $rasoResp = $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '935710']);
            $noExisteResp = $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '424242']);

            // Mismo status Y mismo mensaje: indistinguibles.
            $rasoResp->assertStatus(422);
            $noExisteResp->assertStatus(422);
            $this->assertSame($noExisteResp->json('message'), $rasoResp->json('message'));

            // Ambos consumieron presupuesto (5/min): con los 2 de arriba + 3 más = 5 hits, el 6º topa.
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '424243']);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '424244']);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '424245']);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['supervisor_pin' => '424246'])
                ->assertStatus(429);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_autorizacion_escribe_auditoria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);

            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)])
                ->assertStatus(201);

            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'date' => '2026-07-10', 'type' => 'holiday_unlocked',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_respuesta_no_filtra_authorized_by_ni_method(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)]);

            $res->assertStatus(201)->assertJson(['kind' => 'holiday', 'date' => '2026-07-10']);
            $this->assertStringNotContainsString('authorized_by', $res->getContent());
            $this->assertStringNotContainsString('"method"', $res->getContent());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- generateSupervisorQR endurecido (el bypass ALTO del review) --------------------

    /** Un empleado raso NO puede acuñar un QR (antes: sí, y "a nombre" de su admin → bypass). */
    public function test_un_empleado_no_puede_generar_qr(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $this->actingAs($user)->postJson('/api/v1/sync/supervisor/generate-qr', [])->assertStatus(403);
        $this->assertSame(0, DB::table('supervisor_qr_tokens')->count());
    }

    /** El QR se acuña SIEMPRE a nombre del emisor, ignorando cualquier supervisor_id del body. */
    public function test_el_qr_se_acuna_a_nombre_del_emisor_no_del_body(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $sup = $this->makeSupervisor($tenant);
        $otroSup = $this->makeSupervisor($tenant);

        // El supervisor intenta acuñar "a nombre de" otro → se ignora, queda a su propio nombre.
        $this->actingAs($sup)->postJson('/api/v1/sync/supervisor/generate-qr', ['supervisor_id' => $otroSup->id])
            ->assertStatus(200);

        $row = DB::table('supervisor_qr_tokens')->latest('id')->first();
        $this->assertSame($sup->id, (int) $row->supervisor_id, 'el token debe pertenecer al emisor, no al supervisor_id del body');
    }

    // ---- El endpoint de rehidratación today --------------------------------------------

    public function test_today_reporta_feriado_bloqueante_y_sin_autorizacion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [, $user] = $this->makeSetup();

            $this->actingAs($user)->getJson('/api/v1/clock/overtime/today')
                ->assertStatus(200)
                ->assertJson(['is_holiday_today' => true, 'holiday_blocks' => true, 'authorized' => false]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_today_refleja_la_autorizacion_existente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $sup = $this->makeSupervisor($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/authorize-overtime', ['qr_token' => $this->makeQrToken($sup)]);

            $this->actingAs($user)->getJson('/api/v1/clock/overtime/today')
                ->assertStatus(200)
                ->assertJson(['authorized' => true]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
