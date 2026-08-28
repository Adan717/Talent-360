<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El respaldo lleva la bitácora, y la bitácora sólo VIAJA de ida (2026-08-28, r2-d).
 *
 * «Una exportación sin la bitácora no le sirve al cliente como evidencia»: el export ya salía
 * con los fichajes pero SIN `time_entries_historial` ni `asistencia_correcciones` — justo las
 * tablas que prueban qué pasó y quién lo movió. Ahora salen. Y por la puerta contraria, el
 * import las RECHAZA aunque vengan en el archivo con firma válida: el historial sólo lo escribe
 * el trigger de Postgres y las correcciones sólo su servicio — aceptar filas de un archivo sería
 * dejar que un archivo falsifique la bitácora inmutable.
 */
class RespaldoConBitacoraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Respaldo QA', 'subdomain' => 'respaldoqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@respaldoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function sembrarBitacora(): void
    {
        // En sqlite el trigger de Postgres no existe: se siembran las filas a mano, que para
        // el export da igual QUIÉN las escribió.
        DB::table('time_entries_historial')->insert([
            'time_entry_id' => 1, 'tenant_id' => $this->tenant->id, 'operacion' => 'INSERT',
            'fila_despues' => json_encode(['time' => '09:00:00']), 'origen' => 'prueba',
        ]);
        DB::table('asistencia_correcciones')->insert([
            'tenant_id' => $this->tenant->id, 'time_entry_id' => 1, 'tipo' => 'anulacion',
            'motivo' => 'Fichaje duplicado de la prueba en vivo',
            'autorizado_por' => $this->admin->id, 'empleado_user_id' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_export_incluye_la_bitacora_y_las_correcciones(): void
    {
        $this->sembrarBitacora();

        $res = $this->actingAs($this->admin)->getJson('/api/v1/tenant/backup/export');

        $res->assertStatus(200);
        $data = $res->json('data');

        $this->assertArrayHasKey('time_entries_historial', $data, 'sin la bitácora el respaldo no es evidencia');
        $this->assertArrayHasKey('asistencia_correcciones', $data);
        $this->assertCount(1, $data['time_entries_historial']);
        $this->assertSame('Fichaje duplicado de la prueba en vivo', $data['asistencia_correcciones'][0]['motivo']);
    }

    /** La bitácora de OTRA empresa jamás se cuela en mi respaldo. */
    public function test_el_export_no_arrastra_la_bitacora_de_otro_tenant(): void
    {
        $otro = Tenant::create(['name' => 'Otro', 'subdomain' => 'otrorespaldo', 'plan' => 'enterprise', 'is_active' => true]);
        DB::table('time_entries_historial')->insert([
            'time_entry_id' => 99, 'tenant_id' => $otro->id, 'operacion' => 'DELETE',
            'fila_antes' => json_encode(['time' => '08:00:00']),
        ]);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/tenant/backup/export');

        $this->assertCount(0, $res->json('data.time_entries_historial'));
    }

    /**
     * LA PUERTA DE VUELTA, CERRADA: un archivo con firma VÁLIDA que trae filas de bitácora no
     * logra escribirlas. Se exporta (firma real), se borra la bitácora local y se reimporta el
     * mismo archivo: los fichajes vuelven, la bitácora NO — porque a la bitácora no la escribe
     * ningún archivo.
     */
    public function test_el_import_rechaza_las_tablas_de_bitacora_aunque_la_firma_sea_valida(): void
    {
        $this->sembrarBitacora();

        $archivo = $this->actingAs($this->admin)->getJson('/api/v1/tenant/backup/export')->content();

        DB::table('time_entries_historial')->delete();
        DB::table('asistencia_correcciones')->delete();

        $res = $this->actingAs($this->admin)->postJson('/api/v1/tenant/backup/import', [
            'backup_json' => $archivo,
        ]);

        $res->assertStatus(200);
        $this->assertSame(0, DB::table('time_entries_historial')->count(), 'el historial sólo lo escribe el trigger, nunca un archivo');
        $this->assertSame(0, DB::table('asistencia_correcciones')->count(), 'las correcciones sólo las escribe su servicio');
    }
}
