<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DECISIÓN DE PRODUCTO (2026-08-05): fuera la promesa del bono de $500.
 *
 * La Academia le anunciaba al colaborador "¡Premio por Certificación! Bono de incentivo de $500.00
 * MXN al completarlo" en cuanto un curso traía `incentive_bonus_cents` mayor que cero — y no hay
 * nada en el sistema que pague eso: ni regla de quién lo gana, ni cable a nómina. La promesa se
 * quitó de la pantalla; esta prueba cuida las dos fuentes desde donde se colaba un valor a la
 * columna (las plantillas importables y el seeder), para que no vuelva por la puerta de atrás.
 *
 * Vuelve cuando exista la regla de negocio y el pago automático con ancla anti-doble-pago.
 */
class AcademiaSinPromesaDeBonoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ninguna_plantilla_importable_trae_bono(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $admin->id)->update(['tenant_id' => 1]);

        $plantillas = $this->actingAs($admin->fresh())
            ->getJson('/api/v1/academy/course-templates')->json();

        foreach ($plantillas as $p) {
            $this->assertSame(0, (int) $p['incentive_bonus_cents'],
                "la plantilla '{$p['title']}' volvería a prometer dinero que nadie paga");
        }
    }

    public function test_el_seeder_de_demostracion_tampoco_lo_trae(): void
    {
        $this->seed(\Database\Seeders\AcademySeeder::class);

        $conBono = DB::table('academy_courses')->where('incentive_bonus_cents', '>', 0)->get();

        $this->assertCount(0, $conBono,
            'el seeder sembraba $500 en el curso de ascenso: ' . $conBono->pluck('title')->implode(', '));
    }
}
