<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un documento RECHAZADO cuenta como faltante (2026-08-22, fase 8 del guion).
 *
 * El expediente son 6 documentos fijos. El resumen contaba como "ya subido" cualquier tipo con
 * una fila, sin mirar su estado: con la CURP rechazada, la pantalla decía "faltan 4" cuando en
 * realidad faltaban 5. Justo el renglón que el administrador tiene que perseguir —el que hay que
 * volver a pedir— era el que se volvía invisible.
 */
class ExpedienteRechazadoCuentaComoFaltanteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Employee $empleado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Exp QA', 'subdomain' => 'expqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@expqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@expqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $this->empleado = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
    }

    private function documento(string $tipo, string $status): int
    {
        return DB::table('employee_documents')->insertGetId([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->empleado->id,
            'doc_type' => $tipo, 'original_name' => $tipo . '.pdf', 'path' => 'x/' . $tipo,
            'mime' => 'application/pdf', 'size_bytes' => 100, 'status' => $status,
            'uploaded_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resumen(): array
    {
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/documentos/expedientes');
        $res->assertOk();

        return collect($res->json('employees'))->firstWhere('employee_id', $this->empleado->id);
    }

    public function test_el_expediente_vacio_tiene_seis_faltantes(): void
    {
        $this->assertSame(6, $this->resumen()['faltantes']);
    }

    public function test_uno_validado_baja_el_faltante(): void
    {
        $this->documento('ine', 'validado');

        $r = $this->resumen();
        $this->assertSame(5, $r['faltantes']);
        $this->assertSame(1, $r['validados']);
    }

    public function test_uno_rechazado_sigue_contando_como_faltante(): void
    {
        $this->documento('ine', 'validado');
        $this->documento('curp', 'rechazado');

        $r = $this->resumen();
        $this->assertSame(5, $r['faltantes'], 'un rechazado hay que volver a subirlo: sigue faltando');
        $this->assertSame(1, $r['validados']);
        $this->assertSame(2, $r['subidos']);
        $this->assertSame(1, $r['rechazados']);
    }

    /** Uno pendiente de revisar SÍ está entregado: no falta, sólo falta revisarlo. */
    public function test_uno_pendiente_no_cuenta_como_faltante(): void
    {
        $this->documento('ine', 'pendiente');

        $this->assertSame(5, $this->resumen()['faltantes']);
    }

    /** Si lo vuelve a subir y esta vez se acepta, deja de faltar. */
    public function test_al_reponerlo_deja_de_faltar(): void
    {
        $this->documento('curp', 'rechazado');
        $this->assertSame(6, $this->resumen()['faltantes']);

        DB::table('employee_documents')->where('doc_type', 'curp')->update(['status' => 'validado']);

        $this->assertSame(5, $this->resumen()['faltantes']);
    }
}
