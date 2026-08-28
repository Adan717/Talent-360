<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La bandeja §67.C suma, no sólo lista (2026-08-28, revisión externa r2-c).
 *
 * «flagged_for_review es bitácora, no control, hasta que alguien lo sume.» La marca por fichaje
 * ya existía; lo que faltaba era el PATRÓN: un corte de red real marca a media sucursal UN día,
 * el que se quita retardos "offline" se marca SOLO y muchos días. Ninguna fila individual le
 * decía eso al supervisor — el agregado de reincidencia (veces y días distintos por persona,
 * últimos 90 días) sí.
 */
class ReincidenciaDeFichajesFlaggeadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Reincidencia QA', 'subdomain' => 'reincidenciaqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@reincidenciaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function persona(string $nombre): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@reincidenciaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);

        return $user;
    }

    private function fichajeFlaggeado(User $user, string $fecha, array $detalles = []): void
    {
        TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'date' => $fecha, 'type' => 'check_in', 'time' => '09:45:00',
            'employee_name_at_time' => $user->name,
            'flagged_for_review' => true,
            'details' => json_encode($detalles ?: ['deriva_min' => 45, 'hora_reclamada' => '09:00:00']),
        ]);
    }

    /** El reincidente destaca: 3 marcas en 2 días vs 1 marca del corte de red aislado. */
    public function test_la_bandeja_suma_la_reincidencia_por_persona(): void
    {
        $reincidente = $this->persona('Reincidente Serial');
        $aislado = $this->persona('Corte De Red Real');

        $this->fichajeFlaggeado($reincidente, now()->subDays(10)->toDateString());
        $this->fichajeFlaggeado($reincidente, now()->subDays(10)->toDateString());
        $this->fichajeFlaggeado($reincidente, now()->subDays(3)->toDateString());
        $this->fichajeFlaggeado($aislado, now()->subDays(3)->toDateString());

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/clock/flagged-punches');

        $res->assertStatus(200)->assertJsonPath('success', true);

        $reincidencia = collect($res->json('reincidencia'));
        $this->assertCount(2, $reincidencia);

        // Ordenado por veces: el patrón arriba, el accidente abajo.
        $primero = $reincidencia->first();
        $this->assertSame('Reincidente Serial', $primero['nombre']);
        $this->assertSame(3, (int) $primero['veces']);
        $this->assertSame(2, (int) $primero['dias'], 'días DISTINTOS, no fichajes');

        $segundo = $reincidencia->last();
        $this->assertSame('Corte De Red Real', $segundo['nombre']);
        $this->assertSame(1, (int) $segundo['veces']);
    }

    /** La ventana es de 90 días: lo viejo no persigue a nadie para siempre. */
    public function test_lo_de_hace_mas_de_90_dias_no_cuenta(): void
    {
        $persona = $this->persona('Pecado Viejo');
        $this->fichajeFlaggeado($persona, now()->subDays(120)->toDateString());

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/clock/flagged-punches');

        $this->assertCount(0, $res->json('reincidencia'));
    }

    /** La lista trae `details` para que la bandeja pueda MOSTRAR el porqué (deriva, hora reclamada). */
    public function test_cada_fila_lleva_sus_detalles_para_mostrar_el_porque(): void
    {
        $persona = $this->persona('Con Su Porque');
        $this->fichajeFlaggeado($persona, now()->subDays(2)->toDateString(), ['deriva_min' => 95, 'hora_reclamada' => '08:00:00']);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/clock/flagged-punches');

        $fila = collect($res->json('data'))->first();
        $detalles = json_decode($fila['details'] ?? '', true);
        $this->assertSame(95, $detalles['deriva_min']);
    }
}
