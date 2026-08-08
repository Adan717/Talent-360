<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Monitor 360 — ronda "la pantalla dice la verdad" (2026-08-08).
 *
 * La auditoría del módulo encontró que varias cosas del tablero no eran ciertas:
 * el chat servía los mensajes MÁS VIEJOS en vez de los últimos, las tareas cerradas por
 * la tarde desaparecían del contador (día en UTC), los proveedores solo existían en el
 * navegador que los tecleó, y el botón de sugerencias agradecía y tiraba el texto.
 */
class MonitorDiceLaVerdadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Monitor QA', 'subdomain' => 'monitorqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa QA',
            'email' => 'jefa@monitorqa.test', 'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function monitor(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/monitor')
            ->assertOk()
            ->json('data');
    }

    /** El chat debe traer los ÚLTIMOS mensajes, no los primeros de la historia. */
    public function test_el_chat_trae_los_ultimos_mensajes_no_los_mas_viejos(): void
    {
        // 60 mensajes: con `asc + limit 50` el #60 (el de hoy) nunca aparecía.
        foreach (range(1, 60) as $i) {
            DB::table('internal_messages')->insert([
                'tenant_id' => $this->tenant->id,
                'sender_id' => $this->admin->id,
                'receiver_id' => null,
                'type' => 'general',
                'content' => "Mensaje {$i}",
                'created_at' => Carbon::now()->subMinutes(200 - $i),
                'updated_at' => Carbon::now()->subMinutes(200 - $i),
            ]);
        }

        $chat = collect($this->monitor()['chat'])->pluck('content');

        $this->assertContains('Mensaje 60', $chat, 'el más reciente TIENE que estar');
        $this->assertNotContains('Mensaje 1', $chat, 'el más viejo se queda fuera del corte');
        // Y se pinta en orden cronológico (el hilo se lee de arriba hacia abajo).
        $this->assertSame('Mensaje 60', $chat->last());
    }

    /** Una tarea cerrada por la tarde-noche local sigue contando HOY. */
    public function test_tarea_completada_de_noche_cuenta_en_el_dia_del_tenant(): void
    {
        $colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colab', 'email' => 'colab@monitorqa.test',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $colaborador->id,
            'name' => 'Colab', 'is_active_employee' => true,
        ]);

        // 03:00 UTC = 21:00 del día ANTERIOR en México: la tarea se cerró "anoche" en local.
        $ahoraUtc = Carbon::parse('2026-08-08 03:00:00', 'UTC');
        Carbon::setTestNow($ahoraUtc);

        $hoyLocal = $ahoraUtc->copy()->timezone('America/Mexico_City')->toDateString();

        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $colaborador->id,
            'date' => $hoyLocal, 'type' => 'check_in', 'time' => '09:00:00',
            'created_at' => $ahoraUtc, 'updated_at' => $ahoraUtc,
        ]);

        $taskId = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenant->id, 'title' => 'Corte de caja',
            'estimated_mins' => 20, 'points' => 10, 'priority' => 'normal',
            'created_at' => $ahoraUtc, 'updated_at' => $ahoraUtc,
        ]);

        DB::table('task_assignments')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'task_id' => $taskId, 'user_id' => $colaborador->id, 'status' => 'completed',
            'date' => $hoyLocal, 'created_at' => $ahoraUtc, 'updated_at' => $ahoraUtc,
        ]);

        $users = collect($this->monitor()['users']);
        $fila = $users->firstWhere('user_id', $colaborador->id);

        $this->assertNotNull($fila, 'el colaborador checado tiene que aparecer en el monitor');
        $this->assertSame(1, $fila['completed_tasks_count'],
            'la tarea cerrada a las 21:00 locales cuenta hoy, aunque en UTC ya sea mañana');

        Carbon::setTestNow();
    }

    /** Turno nocturno: a media noche NO se le dice "Jornada terminada" a quien acaba de entrar. */
    public function test_turno_nocturno_no_aparece_como_jornada_terminada(): void
    {
        $velador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Velador', 'email' => 'velador@monitorqa.test',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $velador->id, 'name' => 'Velador',
            'is_active_employee' => true, 'shiftStart' => '22:00', 'shiftEnd' => '06:00',
        ]);

        // 05:00 UTC = 23:00 en México: una hora dentro del turno que empezó a las 22:00.
        $ahoraUtc = Carbon::parse('2026-08-09 05:00:00', 'UTC');
        Carbon::setTestNow($ahoraUtc);
        $hoyLocal = $ahoraUtc->copy()->timezone('America/Mexico_City')->toDateString();

        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $velador->id,
            'date' => $hoyLocal, 'type' => 'check_in', 'time' => '22:00:00',
            'created_at' => $ahoraUtc, 'updated_at' => $ahoraUtc,
        ]);

        $fila = collect($this->monitor()['users'])->firstWhere('user_id', $velador->id);

        $this->assertNotNull($fila);
        $this->assertNotSame('Jornada terminada', $fila['time_remaining'],
            'el turno cruza medianoche: le quedan ~7 h, no está terminado');

        Carbon::setTestNow();
    }

    /** Los proveedores viven en el servidor, no en el navegador que los tecleó. */
    public function test_los_proveedores_registrados_los_ve_cualquier_mando(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/dashboard/vendors', [
            'vendor_name' => 'Lácteos del Valle',
            'driver_name' => 'Chofer Ruiz',
            'order_ref' => 'OC-9911',
        ])->assertOk();

        // Otra sesión / otro dispositivo: el panel debe traerlo igual.
        $vendors = collect($this->monitor()['vendors']);
        $registrado = $vendors->firstWhere('vendor_name', 'Lácteos del Valle');

        $this->assertNotNull($registrado, 'el proveedor tiene que venir del servidor');
        $this->assertSame('in_premises', $registrado['status']);

        // Y su salida se registra con el id REAL que dio el servidor.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/dashboard/vendors/{$registrado['id']}/complete")
            ->assertOk();

        $this->assertSame('completed', DB::table('vendor_logs')->value('status'));
    }

    /** La sugerencia de función se guarda de verdad (antes se agradecía y se tiraba). */
    public function test_la_sugerencia_de_funcion_queda_registrada(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/feature-suggestions', [
            'suggestion' => 'Quisiera ver el control de vacaciones en el mismo tablero.',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $ticket = DB::table('support_tickets')->first();

        $this->assertNotNull($ticket, 'sin fila no hay sugerencia: eso era el bug');
        $this->assertSame('Quisiera ver el control de vacaciones en el mismo tablero.', $ticket->description);
        $this->assertSame($this->tenant->id, (int) $ticket->tenant_id);
        $this->assertSame('jefa@monitorqa.test', $ticket->contact_email);
        // created_by apunta a platform_users: aquí el autor es un users del tenant (§29/§30).
        $this->assertNull($ticket->created_by);
    }

    /**
     * `view_reports` es capacidad de LECTURA: deja mirar el tablero, no operar sobre él.
     * `permission:` es OR, así que el grupo único de antes le daba el Kill-Switch a un
     * puesto de solo reportes.
     */
    public function test_solo_view_reports_mira_el_tablero_pero_no_cierra_turnos(): void
    {
        $puesto = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Analista', 'area' => 'Administración',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $permisoId = DB::table('permissions')->where('name', 'view_reports')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => 'view_reports', 'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('role_permissions')->insert([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $puesto,
            'permission_id' => $permisoId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $analista = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Analista', 'email' => 'analista@monitorqa.test',
            'password' => bcrypt('password'), 'role' => 'supervisor',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $analista->id, 'name' => 'Analista',
            'job_role_id' => $puesto, 'is_active_employee' => true,
        ]);

        // Mirar: sí.
        $this->actingAs($analista)->getJson('/api/v1/admin/dashboard/monitor')->assertOk();

        // Operar: no. Kill-switch, crear tarea y escribir al equipo quedan fuera.
        $this->actingAs($analista)
            ->postJson('/api/v1/admin/dashboard/force-close-shift', ['user_id' => $this->admin->id])
            ->assertForbidden();
        $this->actingAs($analista)
            ->postJson('/api/v1/admin/dashboard/send-message', ['content' => 'Aviso'])
            ->assertForbidden();
    }

    /** Nadie puede firmar un mensaje con el nombre de otro (suplantación). */
    public function test_no_se_puede_escribir_a_nombre_del_jefe(): void
    {
        $raso = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Empleado Raso',
            'email' => 'suplantador@monitorqa.test', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        // Manda el id del ADMIN como remitente: antes el mensaje aparecía firmado por la jefa.
        $this->actingAs($raso)->postJson('/api/v1/sync/message', [
            'sender_id' => $this->admin->id,
            'content' => 'Mañana todos descansan, dice la jefa.',
            'type' => 'general',
        ])->assertOk();

        $msg = DB::table('internal_messages')->latest('id')->first();

        $this->assertSame($raso->id, (int) $msg->sender_id,
            'el remitente es la SESIÓN, no el id que mandó el cliente');
        $this->assertNotSame($this->admin->id, (int) $msg->sender_id);
    }

    /** Un colaborador raso no puede mandar sugerencias a nombre de la empresa. */
    public function test_el_colaborador_raso_no_entra_a_sugerencias(): void
    {
        $raso = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Raso', 'email' => 'raso@monitorqa.test',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        $this->actingAs($raso)->postJson('/api/v1/feature-suggestions', [
            'suggestion' => 'Hola',
        ])->assertForbidden();

        $this->assertDatabaseCount('support_tickets', 0);
    }
}
