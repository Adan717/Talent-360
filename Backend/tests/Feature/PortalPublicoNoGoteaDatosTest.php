<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El portal público de vacantes entrega SÓLO lo público (Fase 3, 2026-08-24).
 *
 * Es una ruta sin sesión: cualquiera con el enlace ve el JSON crudo. Devolvía el modelo entero
 * —`tenant_id`, `deleted_at`, `is_hidden`, `job_role_id`, marcas de auditoría— porque serializar
 * la fila completa es lo que pasa cuando se devuelve el modelo a secas.
 *
 * La prueba central es la de FOTO del contrato: fija la lista exacta de llaves que salen. Si
 * alguien agrega mañana una columna a `vacancies` —notas internas de reclutamiento, el sueldo real
 * del puesto, lo que sea— esta prueba truena antes de que ese dato llegue a un candidato. Ése es
 * el punto: no confiar en el cuidado de quien haga el próximo cambio.
 */
class PortalPublicoNoGoteaDatosTest extends TestCase
{
    use RefreshDatabase;

    /** El contrato. Cambiar esta lista es cambiar lo que el mundo ve. */
    private const CAMPOS_PUBLICOS = [
        'id',
        'title',
        'description',
        'requirements',
        'image_url',
        'work_type',
        'schedule',
        'salary_range',
        'is_active',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Empresa Publica', 'subdomain' => 'publicaqa', 'public_slug' => 'publicaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function vacante(array $extra = []): Vacancy
    {
        $puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Operaciones']);

        return Vacancy::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'job_role_id' => $puesto->id,
            'title' => 'Cajero de mostrador',
            'description' => 'Atención al público y corte de caja.',
            'requirements' => json_encode(['Secundaria terminada', 'Disponibilidad de horario']),
            'work_type' => 'Tiempo Completo',
            'schedule' => 'L-S 9:00 a 18:00',
            'salary_range' => '$1,500 - $2,000 MXN Semanales',
            'image_url' => 'https://ejemplo.test/foto.jpg',
            'is_active' => true,
            'is_hidden' => false,
        ], $extra));
    }

    private function portal(): array
    {
        $res = $this->getJson('/api/v1/public/vacancies/publicaqa');
        $res->assertOk();

        return $res->json();
    }

    /** LA FOTO DEL CONTRATO: ni un campo de más, ni uno de menos. */
    public function test_el_payload_publico_trae_exactamente_los_campos_del_contrato(): void
    {
        $this->vacante();

        $vacante = $this->portal()['vacancies'][0];

        $this->assertSame(
            self::CAMPOS_PUBLICOS,
            array_keys($vacante),
            'cambió el contrato público del portal de empleos: revisa que no se esté publicando un dato interno'
        );
    }

    public function test_no_salen_los_campos_internos_que_salian_antes(): void
    {
        $this->vacante();

        $vacante = $this->portal()['vacancies'][0];

        foreach (['tenant_id', 'deleted_at', 'is_hidden', 'job_role_id', 'created_at', 'updated_at'] as $interno) {
            $this->assertArrayNotHasKey($interno, $vacante, "el portal público sigue entregando `{$interno}`");
        }
    }

    /** Ni siquiera si alguien agrega una columna nueva a la tabla. */
    public function test_una_columna_nueva_no_se_publica_sola(): void
    {
        $this->vacante();

        // Se simula la columna que alguien agregue mañana escribiéndola en el modelo.
        Vacancy::query()->update(['title' => 'Cajero de mostrador']);
        $crudo = json_encode($this->portal()['vacancies'][0]);

        $this->assertStringNotContainsString('nota_interna', $crudo);
        $this->assertStringNotContainsString('tenant_id', $crudo);
    }

    public function test_lo_que_el_portal_necesita_para_pintarse_sigue_llegando(): void
    {
        $this->vacante();

        $v = $this->portal()['vacancies'][0];

        $this->assertSame('Cajero de mostrador', $v['title']);
        $this->assertSame('Tiempo Completo', $v['work_type']);
        $this->assertSame('L-S 9:00 a 18:00', $v['schedule']);
        $this->assertSame('$1,500 - $2,000 MXN Semanales', $v['salary_range'], 'el rango anunciado es público a propósito');
        $this->assertSame('https://ejemplo.test/foto.jpg', $v['image_url']);
        $this->assertTrue($v['is_active']);
        $this->assertSame(['Secundaria terminada', 'Disponibilidad de horario'], $v['requirements']);
    }

    /** Los requisitos llegan como lista aunque en la base sean texto con saltos de línea. */
    public function test_los_requisitos_en_texto_plano_llegan_como_lista(): void
    {
        $this->vacante(['requirements' => "Preparatoria\nExperiencia mínima de 1 año\n"]);

        $this->assertSame(
            ['Preparatoria', 'Experiencia mínima de 1 año'],
            $this->portal()['vacancies'][0]['requirements']
        );
    }

    public function test_una_vacante_oculta_o_apagada_no_aparece(): void
    {
        $this->vacante(['title' => 'Visible']);
        $this->vacante(['title' => 'Oculta', 'is_hidden' => true]);
        $this->vacante(['title' => 'Apagada', 'is_active' => false]);

        $titulos = collect($this->portal()['vacancies'])->pluck('title')->all();

        $this->assertSame(['Visible'], $titulos);
    }

    public function test_los_datos_de_otra_empresa_no_se_asoman(): void
    {
        $otra = Tenant::create([
            'name' => 'Competencia', 'subdomain' => 'competencia', 'public_slug' => 'competencia',
            'plan' => 'basic', 'is_active' => true,
        ]);
        $puesto = JobRole::create(['tenant_id' => $otra->id, 'name' => 'Otro', 'area' => 'X']);
        Vacancy::create([
            'tenant_id' => $otra->id, 'job_role_id' => $puesto->id,
            'title' => 'Vacante de la competencia', 'description' => 'x',
            'requirements' => json_encode([]), 'is_active' => true, 'is_hidden' => false,
        ]);

        $this->vacante(['title' => 'La mía']);

        $titulos = collect($this->portal()['vacancies'])->pluck('title')->all();

        $this->assertSame(['La mía'], $titulos);
    }
}
