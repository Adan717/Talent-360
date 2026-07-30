<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H17 (tercera jornada de regresión 2026-07-30): la pantalla de comedor decía **"0 ocupados,
 * 5 disponibles"** en TODOS los bloques por más reservas que hubiera, y no marcaba como propio
 * el bloque que el colaborador acababa de reservar.
 *
 * Causa: desajuste de formato entre dos fuentes del mismo dato. `meal_reservations.slot_start`
 * es de tipo TIME, así que el `groupBy('slot_start')` devuelve claves **`"13:00:00"`**, mientras
 * que los bloques configurados (`meal_capacity_settings.available_slots`) son **`"13:00"`**.
 * El `keyBy` compara STRINGS en PHP, así que `$reservationCounts['13:00']` nunca acertaba y
 * `$booked` caía a su `?? 0`. Lo mismo en `is_my_reservation`: `'15:00:00' === '15:00'` es false.
 *
 * ALCANCE HONESTO: el aforo SÍ se aplicaba al reservar — el chequeo de `store()` cuenta en SQL
 * (`where('slot_start', ...)`), y ahí Postgres castea `'13:00'` a TIME y compara bien. El daño
 * era de LECTURA: se ofrecían "5 disponibles" en un bloque lleno y la reserva moría con un 422
 * "está lleno", y cualquier panel de ocupación del comedor mostraba cero permanente.
 *
 * Misma clase de defecto que H16 (`09:00` vs `09:00:00`): dos representaciones de la misma hora
 * comparadas como texto.
 */
class MealSlotsBookedCountTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa', 'subdomain' => 't2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Un puesto real por colaborador: la regla de "piso vacío" bloquea a dos del mismo. */
    private function puesto(string $titulo): int
    {
        return DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => $titulo, 'area' => 'Operaciones',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function colaborador(string $puesto): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'job_role_id' => $this->puesto($puesto),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    /**
     * Inserta una reserva tal y como queda en PRODUCCIÓN.
     *
     * La suite corre en sqlite, que guarda TIME como el texto que le das (`'13:00'`); Postgres
     * lo normaliza a `'13:00:00'`. Por eso el bug de H17 es INVISIBLE si el test se limita a
     * pasar por el endpoint de alta: en sqlite las dos representaciones coinciden por accidente
     * y el `keyBy` acierta. Aquí se fuerza la grafía con segundos —la real— para que el caso se
     * reproduzca en cualquier motor.
     */
    private function reservaComoEnProduccion(User $user, string $slotStart, string $fecha = '2026-07-30'): int
    {
        return DB::table('meal_reservations')->insertGetId([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'job_role_id' => DB::table('employees')->where('user_id', $user->id)->value('job_role_id'),
            'reservation_date' => $fecha,
            'slot_start' => "{$slotStart}:00",
            'slot_end' => sprintf('%02d:00:00', ((int) substr($slotStart, 0, 2)) + 1),
            'status' => 'reserved',
            'employee_name_at_time' => $user->name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function slots(User $user, string $fecha = '2026-07-30'): array
    {
        $res = $this->actingAs($user)->getJson("/api/v1/meal-reservations/slots?date={$fecha}");
        $res->assertStatus(200);
        return collect($res->json('slots'))->keyBy('slot_start')->toArray();
    }

    public function test_el_bloque_reservado_cuenta_como_ocupado(): void
    {
        $ana = $this->colaborador('Cajera');
        $beto = $this->colaborador('Almacen'); // otro puesto: no lo bloquea la regla de piso vacío

        $this->reservaComoEnProduccion($ana, '13:00');

        // EL CASO DEL BUG: antes esto devolvía booked=0 / available=5 con la reserva ya hecha.
        $slots = $this->slots($beto);
        $this->assertSame(1, $slots['13:00']['booked'], 'La reserva existente debe contar como ocupada.');
        $this->assertSame(4, $slots['13:00']['available']);
        $this->assertSame(0, $slots['14:00']['booked'], 'Un bloque sin reservas sigue en cero.');
    }

    public function test_el_colaborador_ve_marcado_su_propio_bloque(): void
    {
        $ana = $this->colaborador('Cajera');

        $this->reservaComoEnProduccion($ana, '15:00');

        $slots = $this->slots($ana);
        $this->assertTrue($slots['15:00']['is_my_reservation'], 'Su propio bloque debe salir marcado.');
        $this->assertFalse($slots['13:00']['is_my_reservation']);
    }

    public function test_lo_que_se_ofrece_coincide_con_lo_que_el_alta_acepta(): void
    {
        // El extremo que faltaba: la pantalla decía "disponible" y el alta respondía "está lleno".
        DB::table('meal_capacity_settings')->insert([
            'tenant_id' => $this->tenantId, 'max_capacity' => 2,
            'available_slots' => json_encode(['12:00', '13:00']),
            'slot_duration_minutes' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([1, 2] as $i) {
            $u = $this->colaborador("Puesto {$i}"); // puestos distintos entre sí
            $this->reservaComoEnProduccion($u, '12:00');
        }

        $tercero = $this->colaborador('Supervision');
        $slots = $this->slots($tercero);

        $this->assertSame(2, $slots['12:00']['booked']);
        $this->assertTrue($slots['12:00']['is_full'], 'Con el aforo agotado el bloque debe salir lleno.');

        // Y el alta debe coincidir con lo que se mostró.
        $this->actingAs($tercero)->postJson('/api/v1/meal-reservations', [
            'date' => '2026-07-30', 'slot_start' => '12:00',
        ])->assertStatus(422);
    }

    public function test_una_reserva_cancelada_libera_el_lugar(): void
    {
        $ana = $this->colaborador('Cajera');
        $beto = $this->colaborador('Almacen');

        $id = $this->reservaComoEnProduccion($ana, '13:00');

        $this->assertSame(1, $this->slots($beto)['13:00']['booked']);

        $this->actingAs($ana)->deleteJson("/api/v1/meal-reservations/{$id}")->assertStatus(200);

        $this->assertSame(0, $this->slots($beto)['13:00']['booked'], 'Cancelar debe liberar el lugar.');
    }
}
