<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Importación masiva de plantilla (2026-08-28).
 *
 * Sin esto, dar de alta a un cliente de cuarenta personas es capturarlas una por una. Lo que estas
 * pruebas cuidan no es que "funcione el CSV", sino que la puerta de bloque respete LAS MISMAS
 * reglas que la de uno: correo opcional y jamás inventado, contraseña que nadie conoce, puesto de
 * la PROPIA empresa, fecha de ingreso obligatoria — y que un archivo con un renglón malo no deje
 * media plantilla dentro.
 */
class ImportacionDePlantillaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Importa QA', 'subdomain' => 'importaqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajera', 'area' => 'Piso de venta']);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@importaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function revisar(string $csv)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees/import/revisar', ['csv' => $csv]);
    }

    private function importar(string $csv)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees/import', ['csv' => $csv]);
    }

    private function csvDe(array ...$renglones): string
    {
        $lineas = ['nombre,correo,puesto,fecha_ingreso,sueldo,periodicidad,rol'];
        foreach ($renglones as $r) {
            $lineas[] = implode(',', $r);
        }

        return implode("\n", $lineas);
    }

    // ------------------------------------------------------------ el camino feliz

    public function test_da_de_alta_a_toda_la_plantilla_de_una_vez(): void
    {
        $csv = $this->csvDe(
            ['Rosa Martinez', 'rosa@empresa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado'],
            ['Luis Herrera', 'luis@empresa.test', 'Cajera', '2026-02-01', '9000', 'mensual', 'supervisor'],
            ['Ana Torres', '', 'Cajera', '2026-03-10', '7500', 'mensual', 'empleado'],
        );

        $this->importar($csv)->assertStatus(201)->assertJsonPath('creados', 3);

        $this->assertSame(3, Employee::where('tenant_id', $this->tenant->id)->count());

        $rosa = Employee::where('name', 'Rosa Martinez')->first();
        $this->assertSame($this->puesto->id, $rosa->job_role_id, 'el puesto se resolvió por su nombre');
        $this->assertSame('2026-01-15', substr((string) $rosa->hire_date, 0, 10));
        $this->assertEquals(250.0, (float) $rosa->salario_diario, 'sueldo mensual 7500 / 30 = 250 diarios');
        $this->assertSame('supervisor', User::where('name', 'Luis Herrera')->first()->role);
    }

    /** El simulacro enseña lo que pasaría y NO escribe. */
    public function test_revisar_no_escribe_nada(): void
    {
        $csv = $this->csvDe(['Rosa Martinez', 'rosa@empresa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado']);

        $res = $this->revisar($csv);

        $res->assertOk()->assertJsonPath('resumen.en_el_archivo', 1)->assertJsonPath('resumen.listos', 1);
        $this->assertSame(0, Employee::where('tenant_id', $this->tenant->id)->count(), 'revisar es un simulacro');
    }

    // ------------------------------------------------- las reglas que no se pueden saltar

    /** NUNCA se inventa un correo: quien no lo trae entra por kiosco con su PIN. */
    public function test_a_quien_no_trae_correo_no_se_le_inventa_uno(): void
    {
        $this->importar($this->csvDe(['Ana Torres', '', 'Cajera', '2026-03-10', '7500', 'mensual', 'empleado']))
            ->assertStatus(201);

        $ana = User::where('name', 'Ana Torres')->first();

        $this->assertNull($ana->email, 'el correo se queda vacío, no se fabrica a partir del nombre');
    }

    /** La contraseña nace aleatoria: no la conoce ni quien importó el archivo. */
    public function test_la_contrasena_no_es_conocida_por_nadie(): void
    {
        $this->importar($this->csvDe(['Rosa Martinez', 'rosa@empresa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado']));

        $rosa = User::where('name', 'Rosa Martinez')->first();

        foreach (['password123', 'Rosa Martinez', 'rosa@empresa.test', '12345678', 'talent360'] as $intento) {
            $this->assertFalse(Hash::check($intento, $rosa->password), "la contraseña no puede ser '{$intento}'");
        }
    }

    /** El puesto tiene que existir EN ESTA empresa: el de otra no sirve. */
    public function test_un_puesto_de_otra_empresa_no_se_acepta(): void
    {
        $otra = Tenant::create(['name' => 'Ajena', 'subdomain' => 'ajenaimporta', 'plan' => 'pro', 'is_active' => true]);
        JobRole::create(['tenant_id' => $otra->id, 'name' => 'Almacenista', 'area' => 'Bodega']);

        $res = $this->importar($this->csvDe(['Rosa Martinez', 'rosa@empresa.test', 'Almacenista', '2026-01-15', '7500', 'mensual', 'empleado']));

        $res->assertStatus(422);
        $this->assertStringContainsString('no existe en esta empresa', json_encode($res->json('errores'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Employee::where('tenant_id', $this->tenant->id)->count());
    }

    /** La fecha de ingreso es obligatoria: de ella cuelga el conteo de faltas del primer periodo. */
    public function test_sin_fecha_de_ingreso_se_rechaza(): void
    {
        $res = $this->importar($this->csvDe(['Rosa Martinez', 'rosa@empresa.test', 'Cajera', '', '7500', 'mensual', 'empleado']));

        $res->assertStatus(422);
        $this->assertStringContainsString('fecha de ingreso', json_encode($res->json('errores'), JSON_UNESCAPED_UNICODE));
    }

    /** Un correo que ya existe en la plataforma no se pisa ni se duplica. */
    public function test_un_correo_ya_registrado_detiene_la_importacion(): void
    {
        $res = $this->importar($this->csvDe(['Otro Admin', 'admin@importaqa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado']));

        $res->assertStatus(422);
        $this->assertStringContainsString('ya está registrado', json_encode($res->json('errores'), JSON_UNESCAPED_UNICODE));
    }

    /** Y un correo repetido DENTRO del archivo se caza antes de escribir. */
    public function test_un_correo_repetido_en_el_archivo_se_caza(): void
    {
        $csv = $this->csvDe(
            ['Rosa Martinez', 'igual@empresa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado'],
            ['Luis Herrera', 'igual@empresa.test', 'Cajera', '2026-02-01', '9000', 'mensual', 'empleado'],
        );

        $res = $this->importar($csv);

        $res->assertStatus(422);
        $this->assertStringContainsString('repetido', json_encode($res->json('errores'), JSON_UNESCAPED_UNICODE));
    }

    // -------------------------------------------------------------- todo o nada

    /** UN renglón malo no deja media plantilla dentro. */
    public function test_un_renglon_malo_no_importa_a_los_demas(): void
    {
        $csv = $this->csvDe(
            ['Rosa Martinez', 'rosa@empresa.test', 'Cajera', '2026-01-15', '7500', 'mensual', 'empleado'],
            ['Luis Herrera', 'luis@empresa.test', 'Cajera', 'NO ES UNA FECHA', '9000', 'mensual', 'empleado'],
            ['Ana Torres', 'ana@empresa.test', 'Cajera', '2026-03-10', '7500', 'mensual', 'empleado'],
        );

        $res = $this->importar($csv);

        $res->assertStatus(422);
        $this->assertSame(
            0,
            Employee::where('tenant_id', $this->tenant->id)->count(),
            'media plantilla importada es peor que ninguna: nadie sabría quién quedó dentro'
        );
        $this->assertStringContainsString('Renglón 3', json_encode($res->json('errores'), JSON_UNESCAPED_UNICODE), 'el error dice QUÉ renglón corregir');
    }

    // --------------------------------------------------------------- avisos, no bloqueos

    /** Sin sueldo se AVISA y se da de alta: criterio del dueño, nada bloquea. */
    public function test_sin_sueldo_avisa_pero_no_bloquea(): void
    {
        $res = $this->revisar($this->csvDe(['Ana Torres', 'ana@empresa.test', 'Cajera', '2026-03-10', '', '', 'empleado']));

        $res->assertOk()->assertJsonPath('resumen.listos', 1);
        $this->assertStringContainsString('sin sueldo', json_encode($res->json('renglones.0.avisos'), JSON_UNESCAPED_UNICODE));

        $this->importar($this->csvDe(['Ana Torres', 'ana@empresa.test', 'Cajera', '2026-03-10', '', '', 'empleado']))
            ->assertStatus(201);
    }

    // --------------------------------------------------------------------- forma del archivo

    /** Los encabezados se entienden con acentos, mayúsculas y espacios. */
    public function test_entiende_los_encabezados_como_los_escribe_una_persona(): void
    {
        $csv = "Nombre;Correo;Puesto;Fecha de Ingreso;Sueldo;Periodicidad\n"
             . "Rosa Martinez;rosa@empresa.test;Cajera;2026-01-15;7500;mensual";

        $this->importar($csv)->assertStatus(201)->assertJsonPath('creados', 1);
    }

    /** La plantilla de ejemplo se puede descargar y trae sus encabezados. */
    public function test_la_plantilla_de_ejemplo_se_descarga(): void
    {
        $res = $this->actingAs($this->admin)->get('/api/v1/employees/import/plantilla.csv');

        $res->assertOk();
        $cuerpo = $res->getContent();
        $this->assertStringContainsString('nombre', $cuerpo);
        $this->assertStringContainsString('fecha_ingreso', $cuerpo);
    }

    /** Un colaborador raso no importa plantilla. */
    public function test_un_empleado_no_puede_importar(): void
    {
        $raso = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Raso', 'email' => 'raso@importaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->actingAs($raso)
            ->postJson('/api/v1/employees/import', ['csv' => $this->csvDe(['X', '', 'Cajera', '2026-01-01', '', '', 'empleado'])])
            ->assertStatus(403);
    }
}
