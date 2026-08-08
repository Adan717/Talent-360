<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Archivo Digital (ronda 2026-08) — el módulo era un mockup sin backend.
 *
 * GestorDocumentos.tsx fabricaba expedientes "validados" en el navegador y la barra de
 * upload jamás enviaba el archivo (pérdida de datos real). Estas pruebas son la red del
 * motor construido: storage privado con uuid, checklist fija de 6 con faltantes honestos,
 * flujo pendiente→validado/rechazado y aislamiento de tenant en cada puerta.
 */
class ArchivoDigitalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Enterprise pasa el gate tenant.module:documentos sin addons. updateOrInsert
        // porque el tenant 1 ya existe sembrado por una migración con otro plan.
        foreach ([1, 2] as $tid) {
            DB::table('tenants')->updateOrInsert(['id' => $tid], [
                'name' => "Tenant {$tid}",
                'subdomain' => "t{$tid}",
                'public_slug' => "t{$tid}",
                'plan' => 'enterprise',
                'max_users' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function usuario(int $tenantId, string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);

        return $user->fresh();
    }

    private function empleado(int $tenantId, string $nombre = 'Colaborador Prueba'): int
    {
        return (int) DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $nombre,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subir(User $actor, int $employeeId, array $overrides = [])
    {
        return $this->actingAs($actor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/v1/admin/documentos/expedientes/{$employeeId}", array_merge([
                'file' => UploadedFile::fake()->create('INE Adan.pdf', 500, 'application/pdf'),
                'doc_type' => 'ine',
            ], $overrides));
    }

    /** 1. Subir a un empleado de MI tenant: 200, disco privado, 'pendiente', uuid en path. */
    public function test_subir_guarda_en_privado_como_pendiente_y_con_uuid(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);

        $this->subir($admin, $empleadoId)->assertOk()->assertJsonPath('doc.status', 'pendiente');

        $fila = DB::table('employee_documents')->first();
        $this->assertNotNull($fila);
        $this->assertSame('INE Adan.pdf', $fila->original_name);
        // El nombre en disco es uuid.ext — jamás el nombre original del cliente.
        $this->assertMatchesRegularExpression(
            '#^expedientes/1/' . $empleadoId . '/[0-9a-f-]{36}\.pdf$#',
            $fila->path
        );
        Storage::disk('local')->assertExists($fila->path);
    }

    /** 2. Subir a un empleado de OTRO tenant: 404 y nada en disco. */
    public function test_no_se_puede_subir_a_un_empleado_de_otro_tenant(): void
    {
        $admin = $this->usuario(1);
        $ajeno = $this->empleado(2);

        $this->subir($admin, $ajeno)->assertNotFound();

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /** 3. Un empleado (rol) no puede ni listar ni descargar. */
    public function test_el_rol_empleado_no_entra_al_archivo(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);
        $this->subir($admin, $empleadoId)->assertOk();
        $docId = DB::table('employee_documents')->value('id');

        $raso = $this->usuario(1, 'empleado');

        $this->actingAs($raso)->getJson('/api/v1/admin/documentos/expedientes')->assertForbidden();
        $this->actingAs($raso)->getJson("/api/v1/admin/documentos/descargar/{$docId}")->assertForbidden();
    }

    /** 4. Descargar el mío: 200 con el nombre original; el de otro tenant: 404. */
    public function test_descargar_respeta_el_tenant(): void
    {
        $adminA = $this->usuario(1);
        $empleadoA = $this->empleado(1);
        $this->subir($adminA, $empleadoA)->assertOk();
        $docId = DB::table('employee_documents')->value('id');

        $this->actingAs($adminA)->getJson("/api/v1/admin/documentos/descargar/{$docId}")
            ->assertOk()
            ->assertHeader('content-disposition');

        $adminB = $this->usuario(2);
        $this->actingAs($adminB)->getJson("/api/v1/admin/documentos/descargar/{$docId}")->assertNotFound();
    }

    /** 5. Tipo o tamaño inválidos: 422 y nada en disco. */
    public function test_rechaza_tipo_y_tamano_invalidos(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);

        $this->subir($admin, $empleadoId, [
            'file' => UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream'),
        ])->assertStatus(422);

        $this->subir($admin, $empleadoId, [
            'file' => UploadedFile::fake()->create('gigante.pdf', 11000, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /** 6. Validar sella; rechazar exige motivo. */
    public function test_validar_y_rechazar(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);
        $this->subir($admin, $empleadoId)->assertOk();
        $docId = DB::table('employee_documents')->value('id');

        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/{$docId}/validar", [
            'accion' => 'validar',
        ])->assertOk()->assertJsonPath('doc.status', 'validado');

        $fila = DB::table('employee_documents')->find($docId);
        $this->assertSame($admin->id, (int) $fila->validated_by);
        $this->assertNotNull($fila->validated_at);

        // Rechazar sin motivo: 422. Con motivo: guarda.
        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/{$docId}/validar", [
            'accion' => 'rechazar',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/{$docId}/validar", [
            'accion' => 'rechazar', 'motivo' => 'Ilegible, re-escanear',
        ])->assertOk()->assertJsonPath('doc.status', 'rechazado');

        $this->assertSame('Ilegible, re-escanear', DB::table('employee_documents')->find($docId)->rejection_reason);
    }

    /** 7. Reemplazo del mismo doc_type: el viejo queda soft-deleted y la checklist muestra el nuevo. */
    public function test_reemplazo_del_mismo_tipo_softborra_el_anterior(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);

        $this->subir($admin, $empleadoId)->assertOk();
        $this->subir($admin, $empleadoId, [
            'file' => UploadedFile::fake()->create('INE nueva.pdf', 300, 'application/pdf'),
        ])->assertOk();

        $filas = DB::table('employee_documents')->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertNotNull($filas[0]->deleted_at, 'el INE viejo debe quedar soft-borrado');
        $this->assertNull($filas[1]->deleted_at);
        // D9: el archivo físico del viejo se conserva (recuperable).
        Storage::disk('local')->assertExists($filas[0]->path);

        $expediente = $this->actingAs($admin)
            ->getJson("/api/v1/admin/documentos/expedientes/{$empleadoId}")
            ->assertOk()->json();
        $ine = collect($expediente['checklist'])->firstWhere('doc_type', 'ine');
        $this->assertSame('INE nueva.pdf', $ine['doc']['original_name']);
    }

    /** 8. Checklist honesta: sin docs 6 faltantes; con INE, 5. */
    public function test_la_checklist_dice_la_verdad(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);

        $resumen = fn () => collect($this->actingAs($admin)
            ->getJson('/api/v1/admin/documentos/expedientes')
            ->assertOk()->json('employees'))->firstWhere('employee_id', $empleadoId);

        $this->assertSame(6, $resumen()['faltantes']);
        $this->assertSame(0, $resumen()['subidos']);

        $this->subir($admin, $empleadoId)->assertOk();

        $this->assertSame(5, $resumen()['faltantes']);
        $this->assertSame(1, $resumen()['subidos']);
        $this->assertSame(0, $resumen()['validados']);
    }

    /** 9. Corporativo: vincular persiste; borrar el curso deja el vínculo en null (FK). */
    public function test_vincular_manual_a_curso_persiste_y_sobrevive_el_borrado_del_curso(): void
    {
        $admin = $this->usuario(1);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/documentos/corporativos', [
                'file' => UploadedFile::fake()->create('Manual Cajas.pdf', 400, 'application/pdf'),
                'category' => 'Manuales de Operación',
            ])->assertOk();
        $docId = DB::table('company_documents')->value('id');

        $curso = AcademyCourse::create([
            'title' => 'Capacitación de Cajas',
            'description' => 'Curso.',
            'course_type' => 'training',
            'is_active' => true,
            'tenant_id' => 1,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/corporativos/{$docId}/vincular", [
            'course_id' => $curso->id,
        ])->assertOk();
        $this->assertSame($curso->id, (int) DB::table('company_documents')->find($docId)->linked_course_id);

        // Un curso de OTRO tenant no se puede vincular.
        $ajeno = AcademyCourse::create([
            'title' => 'Curso ajeno', 'description' => 'x', 'course_type' => 'training',
            'is_active' => true, 'tenant_id' => 2,
        ]);
        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/corporativos/{$docId}/vincular", [
            'course_id' => $ajeno->id,
        ])->assertNotFound();

        // Borrado DEFINITIVO del curso: el FK nullOnDelete limpia el vínculo.
        $curso->forceDelete();
        $this->assertNull(DB::table('company_documents')->find($docId)->linked_course_id);
    }

    /** 11. Un filename de 300 chars no revienta el INSERT en Postgres: se recorta a 255. */
    public function test_un_nombre_de_archivo_kilometrico_se_recorta(): void
    {
        $admin = $this->usuario(1);
        $empleadoId = $this->empleado(1);

        $this->subir($admin, $empleadoId, [
            'file' => UploadedFile::fake()->create(str_repeat('a', 300) . '.pdf', 100, 'application/pdf'),
        ])->assertOk();

        // varchar(255): sqlite no aplica el límite y no lo delataría — la aserción sí.
        $this->assertLessThanOrEqual(255, mb_strlen(DB::table('employee_documents')->value('original_name')));
    }

    /** 12. Curso vinculado SOFT-borrado (el camino real de Academia): el vínculo queda
     *  colgando a propósito (course=null, linked_course_id vivo) y el FE lo muestra
     *  como "Curso eliminado" — no como "Sin vincular". */
    public function test_curso_soft_borrado_expone_vinculo_colgante_honesto(): void
    {
        $admin = $this->usuario(1);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/documentos/corporativos', [
                'file' => UploadedFile::fake()->create('Manual.pdf', 100, 'application/pdf'),
                'category' => 'Seguridad',
            ])->assertOk();
        $docId = DB::table('company_documents')->value('id');

        $curso = AcademyCourse::create([
            'title' => 'Curso Temporal', 'description' => 'x', 'course_type' => 'training',
            'is_active' => true, 'tenant_id' => 1,
        ]);
        $this->actingAs($admin)->postJson("/api/v1/admin/documentos/corporativos/{$docId}/vincular", [
            'course_id' => $curso->id,
        ])->assertOk();

        $curso->delete(); // soft — así borra AcademyController::destroy

        $doc = collect($this->actingAs($admin)->getJson('/api/v1/admin/documentos/corporativos')
            ->assertOk()->json('docs'))->firstWhere('id', $docId);

        $this->assertSame($curso->id, $doc['linked_course_id']);
        $this->assertNull($doc['course'], 'la relación no debe resucitar un curso trashed');
    }

    /** 10. El resumen no incluye empleados de otro tenant. */
    public function test_el_resumen_no_mezcla_tenants(): void
    {
        $admin = $this->usuario(1);
        $mio = $this->empleado(1, 'Mío');
        $ajeno = $this->empleado(2, 'Ajeno');

        $ids = collect($this->actingAs($admin)
            ->getJson('/api/v1/admin/documentos/expedientes')
            ->assertOk()->json('employees'))->pluck('employee_id');

        $this->assertTrue($ids->contains($mio));
        $this->assertFalse($ids->contains($ajeno));
    }
}
