<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H27 — el wizard de giro dejaba la configuración A MEDIAS.
 *
 * `configureNicho` creaba los puestos y las 96 tareas del catálogo, pero ni **organigrama** ni
 * **rutinas**. Dos funciones del producto dependían justo de lo que no creaba:
 *
 *  - Sin `reports_to_role_id`, `TaskValidationPolicy` concluye que nadie tiene supervisor y **no
 *    exige la firma** de nadie (la validación jerárquica es además una función de plan).
 *  - Sin rutinas con `trigger='apertura'`, `StoreOpeningService::triggerOpeningChecklist` no
 *    reparte nada al abrir la tienda: **no hay asignación automática**, hay que dar de alta cada
 *    tarea a mano. El módulo se anuncia como "Automatiza Rutinas".
 *
 * Verificado en la V2: los 7 puestos del giro tenían `reports_to_role_id` NULL —mientras los 4
 * sembrados al crear la empresa sí lo tenían— y no había **una sola rutina en toda la base**.
 *
 * El catálogo ya traía lo necesario para el organigrama (`jerarquiaLlaves`: 1 jefe, 2 supervisión,
 * 3 piso, 4 eventual); sólo no se usaba.
 */
class WizardGiroDejaListoTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 7;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Reposteria QA', 'subdomain' => 'repqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        return $user->fresh();
    }

    private function aplicarGiro(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'reposteria',
        ]);
    }

    private function puestos(): \Illuminate\Support\Collection
    {
        return DB::table('job_roles')->where('tenant_id', $this->tenantId)->get();
    }

    private function rutinas(): \Illuminate\Support\Collection
    {
        return DB::table('routines')->where('tenant_id', $this->tenantId)->get();
    }

    // ---------------- organigrama ----------------

    public function test_el_puesto_de_mando_no_reporta_a_nadie(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $jefe = $this->puestos()->firstWhere('jerarquiaLlaves', 1);

        $this->assertNotNull($jefe, 'El giro debe traer un puesto de mando.');
        $this->assertNull($jefe->reports_to_role_id, 'La cabeza de la empresa no reporta a nadie.');
    }

    public function test_todos_los_demas_puestos_tienen_supervisor(): void
    {
        // EL CASO DEL BUG: quedaban TODOS huérfanos, y sin supervisor no se exige ninguna firma.
        $this->aplicarGiro()->assertStatus(200);

        $puestos = $this->puestos();
        $subordinados = $puestos->where('jerarquiaLlaves', '>', 1);

        $this->assertGreaterThan(0, $subordinados->count());

        foreach ($subordinados as $p) {
            $this->assertNotNull($p->reports_to_role_id,
                "El puesto '{$p->name}' quedó sin supervisor: nadie le validaría una tarea.");
        }
    }

    public function test_cada_puesto_reporta_a_uno_de_nivel_superior(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $puestos = $this->puestos()->keyBy('id');

        foreach ($puestos as $p) {
            if (!$p->reports_to_role_id) {
                continue;
            }
            $jefe = $puestos[$p->reports_to_role_id] ?? null;
            $this->assertNotNull($jefe, 'El supervisor debe ser un puesto de la MISMA empresa.');
            $this->assertLessThan((int) $p->jerarquiaLlaves, (int) $jefe->jerarquiaLlaves,
                "'{$p->name}' reporta a alguien de su mismo nivel o inferior.");
        }
    }

    public function test_el_organigrama_desbloquea_la_firma_del_supervisor(): void
    {
        // La razón de ser del arreglo: con jerarquía, una tarea en modo forzado ya exige firma.
        $this->aplicarGiro()->assertStatus(200);

        $subordinado = $this->puestos()->where('jerarquiaLlaves', '>', 1)->first();

        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'job_role_id' => $subordinado->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tareaId = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Arqueo', 'points' => 30,
            'validation_mode' => 'forced', 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(
            \App\Services\TaskValidationPolicy::requiresValidation(
                $this->tenantId, $user->id, \App\Models\Task::withoutGlobalScopes()->find($tareaId)
            ),
            'Con el organigrama construido, la validación jerárquica debe activarse.'
        );
    }

    // ---------------- rutinas ----------------

    public function test_el_giro_deja_una_rutina_de_apertura(): void
    {
        // EL CASO DEL BUG: no había ninguna rutina, así que abrir la tienda no repartía nada.
        $this->aplicarGiro()->assertStatus(200);

        $apertura = $this->rutinas()->firstWhere('trigger', 'apertura');

        $this->assertNotNull($apertura, 'Sin rutina de apertura no hay asignación automática.');
        $this->assertGreaterThan(0,
            DB::table('routine_task')->where('routine_id', $apertura->id)->count(),
            'Una rutina sin tareas tampoco reparte nada.');
    }

    public function test_la_rutina_de_apertura_va_a_cargo_del_puesto_APERTURADOR(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $apertura = $this->rutinas()->firstWhere('trigger', 'apertura');
        $aCargo = $this->puestos()->firstWhere('id', $apertura->target_role_id);

        $this->assertNotNull($aCargo);
        $this->assertTrue((bool) $aCargo->esAperturador,
            'La apertura la lleva quien tiene llaves, no un puesto cualquiera.');
    }

    public function test_las_tareas_de_la_rutina_son_del_propio_tenant(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $apertura = $this->rutinas()->firstWhere('trigger', 'apertura');
        $tareaIds = DB::table('routine_task')->where('routine_id', $apertura->id)->pluck('task_id');

        $delTenant = DB::table('tasks')->where('tenant_id', $this->tenantId)
            ->whereIn('id', $tareaIds)->count();

        $this->assertSame($tareaIds->count(), $delTenant,
            'Una rutina no puede apuntar a tareas de otra empresa.');
    }

    public function test_no_se_crea_ninguna_rutina_que_nadie_dispare(): void
    {
        // Sólo deben existir rutinas cuyo disparador algún punto del backend CONSULTE de verdad.
        // Hoy el único es 'apertura' (`StoreOpeningService::triggerOpeningChecklist`).
        //
        // Este test nació de un defecto propio: se creó una rutina de 'cierre' que aparecía en el
        // panel del gerente y no repartía ninguna tarea. Si mañana alguien vuelve a añadir un
        // disparador al wizard sin cablear quien lo consuma, este test lo detiene.
        $this->aplicarGiro()->assertStatus(200);

        // 'cierre' entro el 2026-08-03: su consumidor es StoreOpeningService::closeStore
        // (boton "Cerrar sucursal") -> triggerClosingChecklist.
        $disparadoresConsumidos = ['apertura', 'cierre'];

        foreach ($this->rutinas() as $rutina) {
            $this->assertContains($rutina->trigger, $disparadoresConsumidos,
                "La rutina '{$rutina->title}' usa el disparador '{$rutina->trigger}', que ningún "
                . 'código consume: se vería en el panel sin repartir nada.');
        }
    }

    public function test_las_tareas_de_cierre_conservan_su_momento_en_el_catalogo(): void
    {
        // El dato se conserva a propósito aunque hoy no se use: deja el trabajo hecho para cuando
        // se cablee el disparador de cierre. Lo que no se crea es la RUTINA, no la información.
        $this->aplicarGiro()->assertStatus(200);

        $hayTareasDeCierre = DB::table('tasks')
            ->where('tenant_id', $this->tenantId)
            ->where('title', 'like', '%alarma perimetral y verificar reporte%')
            ->exists();

        $this->assertTrue($hayTareasDeCierre,
            'Las tareas de cierre siguen cargándose en el catálogo; sólo no se agrupan en rutina.');

        // Desde 2026-08-03 ADEMAS se agrupan: el wizard crea la rutina de cierre porque ya
        // existe quien la dispare (closeStore). Debe traer tareas vinculadas.
        $cierre = $this->rutinas()->firstWhere('trigger', 'cierre');
        $this->assertNotNull($cierre, 'El giro debe dejar una rutina de cierre.');
        $this->assertGreaterThan(0,
            DB::table('routine_task')->where('routine_id', $cierre->id)->count(),
            'La rutina de cierre debe repartir tareas al declararse el cierre.');
    }

    public function test_reaplicar_el_giro_no_duplica_rutinas(): void
    {
        $this->aplicarGiro()->assertStatus(200);
        $primera = $this->rutinas()->count();

        $this->aplicarGiro()->assertStatus(200);

        $this->assertSame($primera, $this->rutinas()->count(),
            'Reaplicar el giro debe dejar el mismo juego de rutinas, no duplicarlas.');
    }

    public function test_las_rutinas_reaplicadas_apuntan_a_las_tareas_NUEVAS(): void
    {
        // El wizard borra y recrea las tareas al reaplicar; las rutinas no pueden quedar
        // apuntando a filas que ya no existen.
        $this->aplicarGiro()->assertStatus(200);
        $this->aplicarGiro()->assertStatus(200);

        $apertura = $this->rutinas()->firstWhere('trigger', 'apertura');
        $tareaIds = DB::table('routine_task')->where('routine_id', $apertura->id)->pluck('task_id');

        $vivas = DB::table('tasks')->whereIn('id', $tareaIds)->count();

        $this->assertGreaterThan(0, $tareaIds->count());
        $this->assertSame($tareaIds->count(), $vivas,
            'Quedaron vínculos a tareas borradas: la rutina no repartiría nada.');
    }
}
