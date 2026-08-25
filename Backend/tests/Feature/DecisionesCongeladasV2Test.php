<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DECISIONES CONGELADAS PARA LA V2 — Fase 4 (2026-08-24).
 *
 * El dueño decidió NO construir tres cosas, y las tres son decisiones de *no hacer*. Ese tipo de
 * decisión se pierde: no deja código que la recuerde, sólo un párrafo en un documento que nadie
 * relee, y seis meses después alguien "arregla" la ausencia y la deshace sin saber que fue
 * deliberada. Esta prueba es el recordatorio: si alguien construye lo que se decidió no construir,
 * la suite lo detiene y le cuenta por qué.
 *
 *   · **Ley Silla — bandera roja en el Monitor: ABORTADA.** El sistema sigue registrando y
 *     avisando el descanso como hoy. No se agrega una métrica punitiva que, sin nadie asignado a
 *     atenderla, sólo sirve de evidencia de que la empresa sabía y no actuó. Y construirla obligaba
 *     a calcular los minutos de pie también en el servidor: un SEGUNDO reloj para el mismo derecho,
 *     tres días después de eliminar tres relojes duplicados.
 *
 *   · **Matriz de permisos — sin pantalla.** El endpoint se queda como está: `role:admin`,
 *     indelegable. Otorgar permisos es la llave que se queda con el dueño.
 *
 *   · **Plan IA del Monitor — sólo PROPONE.** Que una IA reasigne turnos y tareas escribiendo en
 *     la base es demasiado riesgo para esta fase.
 */
class DecisionesCongeladasV2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Congelado QA', 'subdomain' => 'congeladoqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    private function persona(string $nombre, string $rol): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@congeladoqa.test', 'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    /** Todas las rutas declaradas, con sus verbos. */
    private function rutas(): array
    {
        $lista = [];
        foreach (Route::getRoutes() as $ruta) {
            foreach ($ruta->methods() as $verbo) {
                $lista[] = strtoupper($verbo) . ' ' . $ruta->uri();
            }
        }

        return $lista;
    }

    /**
     * El Plan IA sólo propone. Si aparece una ruta que lo APLIQUE —que escriba las asignaciones
     * que la IA sugirió— esta prueba truena: esa es la decisión que se congeló.
     */
    public function test_el_plan_ia_no_tiene_ruta_que_lo_aplique(): void
    {
        $sospechosas = array_values(array_filter($this->rutas(), function (string $r) {
            $esDeEscritura = str_starts_with($r, 'POST') || str_starts_with($r, 'PUT') || str_starts_with($r, 'PATCH');
            if (!$esDeEscritura) {
                return false;
            }

            return preg_match('/(apply|aplicar|ejecutar|execute).*(work-plan|plan)/i', $r) === 1
                || preg_match('/(work-plan|plan).*(apply|aplicar|ejecutar|execute)/i', $r) === 1;
        }));

        $this->assertSame(
            [],
            $sospechosas,
            "El Plan IA se congeló como 'sólo proponer' (Fase 4, 2026-08-24): una IA que reasigna "
            . 'turnos y tareas escribiendo en la base es demasiado riesgo para esta fase. Si esto '
            . 'se va a construir, es una decisión NUEVA del dueño — no un pendiente olvidado.'
        );
    }

    /** Sugerir sí existe, y sigue existiendo: lo congelado es aplicar, no proponer. */
    public function test_sugerir_el_plan_sigue_disponible(): void
    {
        $this->assertContains('POST api/v1/admin/dashboard/suggest-work-plan', $this->rutas());
    }

    /** La matriz de permisos es indelegable: la llave se queda con el dueño. */
    public function test_la_matriz_de_permisos_sigue_siendo_solo_del_admin(): void
    {
        $supervisor = $this->persona('Supervisora', 'supervisor');
        $empleado = $this->persona('Colaborador', 'empleado');

        $this->actingAs($supervisor)->getJson('/api/v1/admin/permissions/matrix')->assertStatus(403);
        $this->actingAs($empleado)->getJson('/api/v1/admin/permissions/matrix')->assertStatus(403);
        $this->actingAs($supervisor)->putJson('/api/v1/admin/permissions/matrix', [])->assertStatus(403);
    }

    public function test_el_admin_si_puede_consultar_la_matriz(): void
    {
        JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Operaciones']);

        $this->actingAs($this->persona('Jefa', 'admin'))
            ->getJson('/api/v1/admin/permissions/matrix')
            ->assertOk();
    }

    /**
     * Ley Silla: el sistema AVISA, no vigila. Si aparece un endpoint que reporte a quien se pasó
     * del límite sin descansar, esta prueba truena — esa métrica se abortó a propósito.
     */
    public function test_no_existe_un_endpoint_que_delate_a_quien_no_descansa(): void
    {
        $sospechosas = array_values(array_filter($this->rutas(), function (string $r) {
            return preg_match('/silla.*(alert|bandera|flag|incumpl|violaci|infracci)/i', $r) === 1
                || preg_match('/(alert|bandera|flag|incumpl|violaci|infracci).*silla/i', $r) === 1;
        }));

        $this->assertSame(
            [],
            $sospechosas,
            "La bandera roja de Ley Silla se abortó (Fase 4, 2026-08-24): sin nadie asignado a "
            . 'atenderla, una alerta que nadie mira no protege a la empresa — prueba que sabía y '
            . 'no actuó. Además obligaba a un SEGUNDO reloj para el mismo derecho.'
        );
    }
}
