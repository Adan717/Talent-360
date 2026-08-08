<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §67 — borrado arbitrario de archivos por `details.photo_url` (cerrado 2026-08-08).
 *
 * La foto de fichaje se guardaba TAL CUAL como la mandara el cliente en `details`, y
 * `clock-photos:purge` —programado solo, cada día a las 03:15— hacía:
 *
 *     @unlink(public_path(ltrim($entry->photo_url, '/')))
 *
 * Con `photo_url = "../.env"` eso resuelve a `/var/www/.env`. Cualquier colaborador con
 * sesión podía marcar un fichaje y dejar PROGRAMADO el borrado del .env del servidor —o de
 * un expediente del storage privado— para cuando venciera la retención de 90 días.
 *
 * Dos cerrojos, y esta prueba comprueba los dos: la entrada se limpia al fichar, y la purga
 * se niega a salir de su carpeta aunque una fila vieja traiga basura.
 */
class FotoFichajeNoBorraArchivosTest extends TestCase
{
    use RefreshDatabase;

    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Fichaje QA', 'subdomain' => 'fichajeqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 15,
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador', 'email' => 'colab@fichajeqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Colaborador', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00',
        ]);
    }

    /** Cerrojo 1: al fichar, una ruta con `..` no se guarda. */
    public function test_el_cliente_no_puede_nombrar_un_archivo_del_servidor(): void
    {
        $this->actingAs($this->colaborador)->postJson('/api/v1/clock/punch', [
            'user_id' => $this->colaborador->id,
            'type' => 'check_in',
            'details' => ['photo_url' => '../.env'],
        ]);

        $guardado = DB::table('time_entries')->latest('id')->value('photo_url');

        $this->assertNull($guardado, 'una ruta con `..` no puede llegar a la base');
    }

    /** Y tampoco por otras variantes del mismo truco. */
    public function test_las_variantes_de_la_ruta_tambien_se_rechazan(): void
    {
        $intentos = [
            '/var/www/.env',                       // absoluta
            'uploads/clock-photos/1/../../../.env', // con `..` en medio
            '..\\..\\.env',                        // barras de Windows
            '/uploads/clock-photos/1/../../.env',   // dentro de la carpeta, pero saliendo
        ];

        foreach ($intentos as $i => $intento) {
            $this->actingAs($this->colaborador)->postJson('/api/v1/clock/punch', [
                'user_id' => $this->colaborador->id,
                'type' => $i === 0 ? 'check_in' : 'check_out',
                'details' => ['photo_url' => $intento],
            ]);

            $this->assertNull(
                DB::table('time_entries')->latest('id')->value('photo_url'),
                "se coló la ruta: {$intento}"
            );
        }
    }

    /**
     * Caso de control: una ruta con la forma que produciría el servidor SÍ se guarda.
     *
     * Sin esta prueba, las dos de arriba pasarían igual si el guard tirara TODO (o si el
     * fichaje fallara por cualquier otro motivo y `photo_url` quedara null de casualidad).
     */
    public function test_una_ruta_legitima_del_servidor_si_se_guarda(): void
    {
        $tenantId = $this->colaborador->tenant_id;

        $this->actingAs($this->colaborador)->postJson('/api/v1/clock/punch', [
            'user_id' => $this->colaborador->id,
            'type' => 'check_in',
            'details' => ['photo_url' => "/uploads/clock-photos/{$tenantId}/a1b2c3d4.jpg"],
        ]);

        $this->assertSame(
            "/uploads/clock-photos/{$tenantId}/a1b2c3d4.jpg",
            DB::table('time_entries')->latest('id')->value('photo_url'),
            'el guard no puede tirar también lo legítimo: si no, §67 nunca podría encenderse'
        );
    }

    /** Cerrojo 2: aunque la fila ya traiga basura, la purga NO sale de su carpeta. */
    public function test_la_purga_no_borra_fuera_de_la_carpeta_de_fotos(): void
    {
        // Un archivo que representa al .env: la purga no debe tocarlo.
        $archivoAjeno = public_path('../archivo_que_no_se_toca.txt');
        file_put_contents($archivoAjeno, 'contenido intacto');

        // Fila envenenada, como la que habría dejado el agujero antes de cerrarlo.
        DB::table('time_entries')->insert([
            'tenant_id' => $this->colaborador->tenant_id,
            'user_id' => $this->colaborador->id,
            'date' => now()->subDays(200)->toDateString(),
            'type' => 'check_in',
            'time' => '09:00:00',
            'photo_url' => '../archivo_que_no_se_toca.txt',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('clock-photos:purge')->assertSuccessful();

        $this->assertFileExists($archivoAjeno, 'la purga se salió de su carpeta y borró un archivo ajeno');
        $this->assertSame('contenido intacto', file_get_contents($archivoAjeno));

        // La referencia sí se limpia de la base (la foto "ya no está" para efectos ARCO).
        $this->assertNull(DB::table('time_entries')->latest('id')->value('photo_url'));

        @unlink($archivoAjeno);
    }

    /** Y la purga sigue haciendo su trabajo con una foto legítima. */
    public function test_la_purga_si_borra_una_foto_de_verdad(): void
    {
        $dir = public_path('uploads/clock-photos/' . $this->colaborador->tenant_id);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $foto = $dir . '/foto_vieja.jpg';
        file_put_contents($foto, 'jpeg falso');

        DB::table('time_entries')->insert([
            'tenant_id' => $this->colaborador->tenant_id,
            'user_id' => $this->colaborador->id,
            'date' => now()->subDays(200)->toDateString(),
            'type' => 'check_in',
            'time' => '09:00:00',
            'photo_url' => "/uploads/clock-photos/{$this->colaborador->tenant_id}/foto_vieja.jpg",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('clock-photos:purge')->assertSuccessful();

        $this->assertFileDoesNotExist($foto, 'la retención de 90 días tiene que seguir cumpliéndose');
    }
}
