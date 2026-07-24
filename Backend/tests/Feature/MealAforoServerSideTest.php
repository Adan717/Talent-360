<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R65 (follow-up (b) de la prueba a ESCALA de R63): el aforo de comida (`maxChairs`) sólo se
 * validaba en el FE (RelojVisual). Un cliente que saltara el FE podía sobre-reservar: el server
 * insertaba cualquier `meal_reservation` sin mirar. Ahora `ClockService::mealReservationConflict`
 * lo enforcea server-side (aforo por bloque, una reserva por persona, choque de puesto), y
 * `ClockController::sync` lo aplica devolviendo 409 al rechazar.
 */
class MealAforoServerSideTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function makeTenant(int $maxChairs = 2, bool $preventRoleOverlap = false, int $stepMins = 15): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Empresa M', 'subdomain' => 'm-' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'mealSettings',
            'value' => json_encode([
                'maxChairs' => $maxChairs, 'stepMins' => $stepMins,
                'startHour' => 13, 'endHour' => 17, 'preventRoleOverlap' => $preventRoleOverlap,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $tenant;
    }

    private function makeUser(int $tenantId, int $mealMinutes = 60, ?int $jobRoleId = null): User
    {
        $user = User::create([
            'tenant_id' => $tenantId, 'name' => 'Colab', 'email' => 'u' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'name' => 'Colab',
            'mealMinutes' => $mealMinutes, 'job_role_id' => $jobRoleId, 'is_active_employee' => true,
        ]);
        return $user;
    }

    /** Inserta una reserva de comida directa (simula reservas previas de otros). */
    private function seedReservation(int $tenantId, int $userId, string $time, string $date = '2026-07-10'): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'date' => $date,
            'type' => 'meal_reservation', 'time' => $time, 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reserve(User $u, string $time, string $date = '2026-07-10')
    {
        return $this->actingAs($u)->postJson('/api/v1/sync/clock', [
            'user_id' => $u->id, 'date' => $date, 'type' => 'meal_reservation', 'time' => $time,
            'details' => $time,
        ]);
    }

    public function test_una_reserva_normal_pasa(): void
    {
        $tenant = $this->makeTenant(maxChairs: 2);
        $u = $this->makeUser($tenant->id);
        $this->reserve($u, '14:00')->assertStatus(200);
        $this->assertSame(1, DB::table('time_entries')->where('type', 'meal_reservation')->count());
    }

    public function test_aforo_lleno_bloquea_la_reserva(): void
    {
        $tenant = $this->makeTenant(maxChairs: 2);
        // Dos compañeros ya reservaron las 14:00 (aforo = 2, lleno).
        $a = $this->makeUser($tenant->id);
        $b = $this->makeUser($tenant->id);
        $this->seedReservation($tenant->id, $a->id, '14:00');
        $this->seedReservation($tenant->id, $b->id, '14:00');

        $c = $this->makeUser($tenant->id);
        $res = $this->reserve($c, '14:00');
        $res->assertStatus(409);
        $this->assertSame(2, DB::table('time_entries')->where('type', 'meal_reservation')->count());
    }

    public function test_una_reserva_por_persona_por_dia(): void
    {
        $tenant = $this->makeTenant(maxChairs: 5);
        $u = $this->makeUser($tenant->id);
        $this->reserve($u, '14:00')->assertStatus(200);
        // Segunda reserva del MISMO usuario el mismo día → 409.
        $this->reserve($u, '15:00')->assertStatus(409);
        $this->assertSame(1, DB::table('time_entries')->where('type', 'meal_reservation')->count());
    }

    public function test_cancelar_libera_el_cupo(): void
    {
        $tenant = $this->makeTenant(maxChairs: 1);
        $a = $this->makeUser($tenant->id);
        $this->seedReservation($tenant->id, $a->id, '14:00'); // aforo 1 → lleno

        $b = $this->makeUser($tenant->id);
        $this->reserve($b, '14:00')->assertStatus(409); // lleno

        // A cancela → libera el único cupo.
        DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $a->id, 'date' => '2026-07-10',
            'type' => 'meal_cancel', 'time' => '00:00', 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reserve($b, '14:00')->assertStatus(200);
    }

    public function test_solapamiento_parcial_por_duracion_cuenta_en_el_aforo(): void
    {
        // maxChairs 1, comida 60 min (4 bloques de 15). A reserva 14:00 → ocupa 14:00-15:00.
        $tenant = $this->makeTenant(maxChairs: 1);
        $a = $this->makeUser($tenant->id, mealMinutes: 60);
        $this->seedReservation($tenant->id, $a->id, '14:00');

        // B intenta 14:30 → solapa el bloque 14:30-14:45 con A → aforo lleno.
        $b = $this->makeUser($tenant->id, mealMinutes: 60);
        $this->reserve($b, '14:30')->assertStatus(409);

        // Pero 15:00 (justo después del rango de A) sí cabe.
        $this->reserve($b, '15:00')->assertStatus(200);
    }

    /**
     * Solapamiento de COLA menor a stepMins: una reserva no alineada a la malla (13:05, 60 min →
     * 13:05-14:05) solapa 14:00-14:05 con otra de 14:00. El muestreo por bloques anclado al start lo
     * pasaba por alto; el barrido de instantes sí lo detecta.
     */
    public function test_solapamiento_de_cola_no_alineado_cuenta_en_el_aforo(): void
    {
        $tenant = $this->makeTenant(maxChairs: 1, stepMins: 15);
        $a = $this->makeUser($tenant->id, mealMinutes: 60);
        $this->seedReservation($tenant->id, $a->id, '14:00'); // 14:00-15:00

        $b = $this->makeUser($tenant->id, mealMinutes: 60);
        // 13:05-14:05 solapa 14:00-14:05 con A → aforo lleno (aunque ningún inicio de bloque de B
        // caiga dentro del rango de A).
        $this->reserve($b, '13:05')->assertStatus(409);
    }

    /** El aforo se cuenta SOLO dentro del tenant: una reserva de otro tenant al mismo horario no cuenta. */
    public function test_el_aforo_no_cruza_tenants(): void
    {
        $t1 = $this->makeTenant(maxChairs: 1);
        $t2 = $this->makeTenant(maxChairs: 1);
        // En t2 alguien ya reservó las 14:00 (llenaría el aforo 1... pero de OTRO tenant).
        $otro = $this->makeUser($t2->id);
        $this->seedReservation($t2->id, $otro->id, '14:00');

        // En t1 el usuario reserva las 14:00 sin problema (el t2 no cuenta).
        $mio = $this->makeUser($t1->id);
        $this->reserve($mio, '14:00')->assertStatus(200);
    }

    /** El MISMO usuario puede reservar → cancelar → volver a reservar (la cancel libera su regla A). */
    public function test_el_mismo_usuario_puede_re_reservar_tras_cancelar(): void
    {
        $tenant = $this->makeTenant(maxChairs: 3);
        $u = $this->makeUser($tenant->id);

        $this->reserve($u, '14:00')->assertStatus(200);
        $this->reserve($u, '15:00')->assertStatus(409); // ya tiene una activa

        // Cancela y vuelve a reservar otro horario → permitido.
        $this->actingAs($u)->postJson('/api/v1/sync/clock', [
            'user_id' => $u->id, 'date' => '2026-07-10', 'type' => 'meal_cancel', 'time' => '00:00',
            'details' => 'cancel',
        ])->assertStatus(200);
        $this->reserve($u, '15:00')->assertStatus(200);
    }

    /** Una reserva con hora no interpretable se rechaza (no deja fila fantasma que ninguna regla cuenta). */
    public function test_hora_invalida_se_rechaza(): void
    {
        $tenant = $this->makeTenant(maxChairs: 3);
        $u = $this->makeUser($tenant->id);
        $this->reserve($u, 'no-es-una-hora')->assertStatus(409);
        $this->assertSame(0, DB::table('time_entries')->where('type', 'meal_reservation')->count());
    }

    public function test_choque_de_puesto_cuando_prevent_role_overlap(): void
    {
        $tenant = $this->makeTenant(maxChairs: 5, preventRoleOverlap: true);
        $cajero = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $gerente = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Gerente', 'area' => 'Piso']);

        $a = $this->makeUser($tenant->id, jobRoleId: $cajero->id);
        $this->seedReservation($tenant->id, $a->id, '14:00');

        // Mismo puesto (Cajero) a la misma hora → choque, aunque haya aforo de sobra.
        $mismo = $this->makeUser($tenant->id, jobRoleId: $cajero->id);
        $this->reserve($mismo, '14:00')->assertStatus(409);

        // Otro puesto (Gerente) sí puede comer a la vez.
        $otro = $this->makeUser($tenant->id, jobRoleId: $gerente->id);
        $this->reserve($otro, '14:00')->assertStatus(200);
    }
}
