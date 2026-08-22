<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El PIN de kiosco se fija desde la ficha del colaborador (2026-08-22).
 *
 * El endpoint existía desde R54 y NADIE lo llamaba: no había pantalla. El PIN lo usan la
 * apertura de emergencia, la validación de tareas y la autorización de entrada tardía, así que
 * sin pantalla esas tres funciones eran inalcanzables para un cliente real. La ficha necesita
 * saber si el colaborador YA tiene PIN (para decir "configurado" o "sin PIN") sin que el hash ni
 * el índice viajen jamás al navegador.
 */
class KioskPinEnLaFichaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ficha_sabe_si_hay_pin_sin_exponer_el_hash(): void
    {
        $tenant = Tenant::create(['name' => 'Ficha PIN', 'subdomain' => 'fichapin', 'plan' => 'enterprise', 'is_active' => true]);
        $admin = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@fichapin.test', 'password' => bcrypt('x'), 'role' => 'admin']);
        $empUser = User::create(['tenant_id' => $tenant->id, 'name' => 'Pedro', 'email' => 'p@fichapin.test', 'password' => bcrypt('x'), 'role' => 'empleado']);
        $emp = Employee::create(['tenant_id' => $tenant->id, 'user_id' => $empUser->id, 'name' => 'Pedro', 'is_active_employee' => true]);

        // Sin PIN todavía.
        $lista = $this->actingAs($admin)->getJson('/api/v1/employees')->assertOk()->json();
        $fila = collect($lista)->firstWhere('id', $emp->id);
        $this->assertNotNull($fila);
        $this->assertFalse($fila['has_kiosk_pin'], 'recién creado no tiene PIN');

        // Se fija desde la ficha.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/employees/{$emp->id}/kiosk-pin", ['pin' => '482913'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $lista = $this->actingAs($admin)->getJson('/api/v1/employees')->assertOk();
        $fila = collect($lista->json())->firstWhere('id', $emp->id);
        $this->assertTrue($fila['has_kiosk_pin'], 'tras fijarlo, la ficha debe saber que hay PIN');

        // Y NUNCA viaja el secreto: ni el hash, ni el índice, ni el PIN en claro.
        $crudo = $lista->getContent();
        $this->assertStringNotContainsString('kiosk_pin_hash', $crudo);
        $this->assertStringNotContainsString('kiosk_pin_lookup', $crudo);
        $this->assertStringNotContainsString('482913', $crudo);
    }
}
