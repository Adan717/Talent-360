<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Eliminar definitivamente" no puede borrar la historia laboral (2026-08-08).
 *
 * `forceDestroy` confiaba en que, si el colaborador tenía históricos, las claves foráneas
 * lanzarían una excepción y su `catch` archivaría "para conservar la integridad histórica".
 * Pero todas esas claves son ON DELETE CASCADE, no RESTRICT: la excepción nunca ocurría y el
 * borrado se llevaba los fichajes, los recibos de nómina YA FIRMADOS y el expediente completo
 * del Archivo Digital — que el art. 804 de la LFT obliga a conservar 5 años.
 *
 * Encima el botón vive en la pestaña de INACTIVOS (justo esa población) y la ruta la puede
 * llamar un supervisor.
 */
class NoSeBorraLaHistoriaLaboralTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Historia QA', 'subdomain' => 'histqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@histqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
        // Un segundo admin, para que el candado del "último administrador" no interfiera.
        User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin 2', 'email' => 'admin2@histqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function colaboradorConHistoria(): Employee
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Veterano', 'email' => 'vet@histqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);

        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'name' => 'Veterano',
            'is_active_employee' => true, 'base_salary' => 3000,
        ]);

        // Tres meses de checadas.
        foreach (range(1, 3) as $i) {
            DB::table('time_entries')->insert([
                'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
                'date' => now()->subDays($i * 30)->toDateString(), 'type' => 'check_in',
                'time' => '09:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Un recibo de nómina YA FIRMADO por el trabajador.
        DB::table('weekly_payrolls')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'start_date' => now()->subDays(7)->toDateString(), 'end_date' => now()->toDateString(),
            'base_salary_paid' => 3000, 'net_pay' => 2800, 'deductions' => 200,
            'status' => 'approved_by_employee', 'employee_approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Su expediente del Archivo Digital.
        DB::table('employee_documents')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'doc_type' => 'ine',
            'original_name' => 'INE.pdf', 'path' => 'expedientes/1/1/' . Str::uuid() . '.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1000, 'status' => 'validado',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $emp;
    }

    public function test_eliminar_definitivamente_conserva_fichajes_recibos_y_expediente(): void
    {
        $emp = $this->colaboradorConHistoria();
        $userId = $emp->user_id;

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$emp->id}/force")
            ->assertOk()
            ->assertJsonPath('archivado', true);

        $this->assertSame(3, DB::table('time_entries')->where('user_id', $userId)->count(),
            'los controles de asistencia se conservan 5 años (art. 804 LFT)');
        $this->assertSame(1, DB::table('weekly_payrolls')->where('employee_id', $emp->id)->count(),
            'un recibo YA FIRMADO no se puede borrar');
        $this->assertSame(1, DB::table('employee_documents')->where('employee_id', $emp->id)->count(),
            'el expediente del Archivo Digital tampoco');
    }

    public function test_el_colaborador_queda_archivado_y_sin_acceso(): void
    {
        $emp = $this->colaboradorConHistoria();

        $this->actingAs($this->admin)->deleteJson("/api/v1/employees/{$emp->id}/force")->assertOk();

        // Fuera del directorio y sin poder entrar: la baja SÍ surte efecto.
        $this->assertNotNull(DB::table('employees')->where('id', $emp->id)->value('deleted_at'));
        $this->assertFalse((bool) DB::table('users')->where('id', $emp->user_id)->value('is_active'));
    }

    /** La respuesta explica POR QUÉ no se borró, en vez de decir "eliminado definitivamente". */
    public function test_se_le_dice_al_dueno_que_se_archivo_y_por_que(): void
    {
        $emp = $this->colaboradorConHistoria();

        $mensaje = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$emp->id}/force")
            ->json('message');

        $this->assertStringContainsString('archivó', $mensaje);
        $this->assertStringContainsString('recibos de nómina', $mensaje);
        $this->assertStringContainsString('804', $mensaje);
    }

    /** Sin historia que conservar, el borrado definitivo SÍ borra (no romper el caso legítimo). */
    public function test_un_alta_por_error_sin_historia_si_se_borra(): void
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Duplicado', 'email' => 'dup@histqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'name' => 'Duplicado',
            'is_active_employee' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$emp->id}/force")
            ->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $emp->id]);
    }
}
