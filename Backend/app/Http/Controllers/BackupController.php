<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class BackupController extends Controller
{
    /**
     * Tablas que viajan en el respaldo, en orden de dependencia.
     *
     * Cambios del 2026-08-11:
     *  - FUERA `companies`: NO tiene columna `tenant_id` (se indexa por id == tenant_id), así que
     *    el `where('tenant_id', ...)` de abajo reventaba con un 500 en Postgres. Es decir: el
     *    respaldo NUNCA funcionó en producción — ni exportar, ni restaurar, ni "subir a Drive".
     *    Sólo guarda los 4 campos de la pantalla de bienvenida; se dejan fuera a propósito.
     *  - DENTRO `employees`, `permissions` y `role_permissions`: un "respaldo completo" que no
     *    incluía los EXPEDIENTES (CURP, RFC, NSS, sueldo, horario) ni quién puede hacer qué no
     *    respaldaba lo que de verdad importa.
     *
     * Lo que NO entra y hay que decirlo en pantalla: los archivos del Archivo Digital y las
     * evidencias (viven en disco, no en la base) y los recibos de nómina timbrados.
     */
    private $tables = [
        'job_roles',
        'role_clock_policies',
        'permissions',
        'role_permissions',
        'users',
        'employees',
        'time_entries',
        'routines',
        'tasks',
        'task_assignments',
        'vacancies',
        'candidates',
        'academy_courses',
        'user_course_progress',
        'system_settings'
    ];

    /**
     * Columnas que NUNCA salen del servidor en un respaldo.
     *
     * El export usaba `DB::table(...)->get()`, que no pasa por el modelo y por tanto ignora su
     * `$hidden`: el JSON llevaba el hash de la contraseña de cada persona, el secreto de 2FA, la
     * llave biométrica, el token del checador y los ids de Google/Apple/Samsung. Y la pantalla lo
     * llamaba "archivo JSON cifrado" cuando sólo va firmado.
     */
    private const COLUMNAS_SENSIBLES = [
        'users' => [
            'password', 'remember_token', 'two_factor_secret', 'biometric_key',
            'fcm_token', 'qr_token', 'google_id', 'apple_id', 'samsung_id',
        ],
        'employees' => [
            'kiosk_pin_hash', 'kiosk_pin_lookup', 'security_pin', 'pin_code', 'invite_token',
        ],
    ];

    /**
     * Check if the tenant has premium permissions (Pro/Enterprise or active Trial)
     */
    private function checkPremiumAccess($user)
    {
        $tenant = $user->tenant;
        if (!$tenant) {
            return false;
        }

        // Trial is active
        if ($tenant->subscription_status === 'trial' || empty($tenant->subscription_status)) {
            if ($tenant->trial_ends_at && now()->lt(Carbon::parse($tenant->trial_ends_at))) {
                return true;
            }
        }

        $plan = strtolower($tenant->plan ?? 'freemium');
        return in_array($plan, ['pro', 'enterprise']);
    }

    /**
     * Por qué columnas se reconoce una fila al reponerla.
     *
     * Casi todas tienen `id`. `system_settings` NO: su clave es (tenant_id, key), así que buscarla
     * por `id` acababa insertando siempre y chocando con su índice único.
     */
    private const CLAVE_DE_FILA = [
        'system_settings' => ['tenant_id', 'key'],
    ];

    /**
     * Valores para las columnas obligatorias que el respaldo NO trae por ser sensibles.
     *
     * Sólo hace falta al INSERTAR una fila que ya no existe: al actualizar, la contraseña que ya
     * tiene la persona se queda como está. Nace una aleatoria que nadie conoce; la persona fija la
     * suya al activar su cuenta, igual que en el alta de RRHH.
     */
    private function rellenoObligatorio(string $table): array
    {
        return $table === 'users'
            ? ['password' => Hash::make(\Illuminate\Support\Str::random(32))]
            : [];
    }

    /** Los datos de una empresa, ya sin las columnas que no deben salir del servidor. */
    private function datosDelTenant(int $tenantId): array
    {
        $datos = [];

        foreach ($this->tables as $table) {
            $ocultas = self::COLUMNAS_SENSIBLES[$table] ?? [];

            $datos[$table] = DB::table($table)->where('tenant_id', $tenantId)->get()
                ->map(fn ($fila) => (object) \Illuminate\Support\Arr::except((array) $fila, $ocultas))
                ->toArray();
        }

        return $datos;
    }

    /**
     * Export tenant data as JSON with SHA-256 HMAC
     */
    public function export(Request $request)
    {
        $user = $request->user();
        if (!$this->checkPremiumAccess($user)) {
            return response()->json([
                'error' => 'Premium Feature Locked',
                'message' => 'La exportación y restauración de copias de seguridad es una característica exclusiva de los planes de pago (Profesional y Empresas).'
            ], 403);
        }

        $tenantId = $user->tenant_id;
        $backupData = $this->datosDelTenant($tenantId);

        // Add metadata
        $metadata = [
            'tenant_id' => $tenantId,
            'exported_at' => now()->toIso8601String(),
            'version' => '1.0.0',
            'company_name' => $user->tenant->name ?? 'Talent360 Tenant'
        ];

        $payload = [
            'metadata' => $metadata,
            'data' => $backupData
        ];

        // Generate HMAC signature
        $jsonStr = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonStr, config('app.key'));
        
        $payload['_signature'] = $signature;

        return response()->json($payload)
            ->header('Content-Disposition', 'attachment; filename="talent360_backup_' . date('Y-m-d_H-i-s') . '.json"');
    }

    /**
     * Import tenant data and restore state
     */
    public function import(Request $request)
    {
        $user = $request->user();
        if (!$this->checkPremiumAccess($user)) {
            return response()->json([
                'error' => 'Premium Feature Locked',
                'message' => 'La restauración de copias de seguridad es una característica exclusiva de los planes de pago.'
            ], 403);
        }

        $request->validate([
            'backup_json' => 'required|string'
        ]);

        $payload = json_decode($request->backup_json, true);

        if (!$payload || !isset($payload['_signature']) || !isset($payload['data'])) {
            return response()->json(['error' => 'Formato de respaldo inválido.'], 400);
        }

        // Validate signature
        $signature = $payload['_signature'];
        unset($payload['_signature']);

        $jsonStr = json_encode($payload);
        $calculatedSignature = hash_hmac('sha256', $jsonStr, config('app.key'));

        if (!hash_equals($calculatedSignature, $signature)) {
            return response()->json([
                'error' => 'Backup Tampered',
                'message' => 'La firma digital del respaldo no coincide. El archivo ha sido alterado o está corrupto.'
            ], 400);
        }

        $tenantId = $user->tenant_id;
        $data = $payload['data'];

        try {
            DB::beginTransaction();

            // RESTAURAR YA NO BORRA NADA (2026-08-11).
            //
            // Aquí había un `DELETE` de todas las filas de la empresa en las 13 tablas de la
            // lista, y sólo después se reinsertaba lo que trajera el archivo. Con eso:
            //  · Al borrar `users` y `job_roles`, las llaves foráneas de `employees` (ambas
            //    ON DELETE SET NULL) dejaban CADA expediente con `user_id` y `job_role_id` en
            //    NULL. Como `employees` no viajaba en el respaldo, nada volvía a unirlos: toda la
            //    plantilla quedaba sin cuenta de acceso y sin puesto, de forma permanente.
            //  · Ese mismo borrado de `users` arrastraba por CASCADE una docena de tablas que
            //    tampoco están en el respaldo (bitácora de auditoría, chat, eventualidades,
            //    evaluaciones, traspasos de llaves, monedero...), que se perdían para siempre.
            //  · Y el "apagado" de llaves foráneas no apagaba nada: `SET CONSTRAINTS ALL DEFERRED`
            //    no hace efecto sobre restricciones que no son DEFERRABLE, que son todas aquí.
            //
            // Ahora es un REPONER: cada fila del archivo se escribe sobre la suya (por id) o se
            // inserta si ya no está. No se borra nada, así que ninguna llave foránea se dispara.
            // Lo que se creó después del respaldo sobrevive — y eso es exactamente lo que hay que
            // decir en pantalla, en vez de llamarlo "restaurar el estado".
            $filas = 0;

            foreach ($this->tables as $table) {
                if (!isset($data[$table]) || !is_array($data[$table])) {
                    continue;
                }

                $claves = self::CLAVE_DE_FILA[$table] ?? ['id'];

                foreach ($data[$table] as $row) {
                    $fila = (array) $row;
                    // Aislamiento: la empresa la pone el servidor, nunca el archivo.
                    $fila['tenant_id'] = $tenantId;

                    $busqueda = [];
                    foreach ($claves as $columna) {
                        if (!array_key_exists($columna, $fila) || $fila[$columna] === null) {
                            $busqueda = [];
                            break;
                        }
                        $busqueda[$columna] = $fila[$columna];
                    }

                    if (empty($busqueda)) {
                        DB::table($table)->insert($fila + $this->rellenoObligatorio($table));
                        $filas++;
                        continue;
                    }

                    $valores = array_diff_key($fila, $busqueda);

                    if (DB::table($table)->where($busqueda)->exists()) {
                        // Sólo se pisan las columnas que el archivo trae: las sensibles no viajan
                        // en el respaldo, así que reponer nunca borra la contraseña de nadie.
                        DB::table($table)->where($busqueda)->update($valores);
                    } else {
                        DB::table($table)->insert($fila + $this->rellenoObligatorio($table));
                    }

                    $filas++;
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Datos repuestos desde el respaldo: {$filas} registros.",
                'rows' => $filas,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Import Error',
                'message' => 'Ocurrió un error al importar los datos: ' . $e->getMessage()
            ], 500);
        }
    }

    // googleSync() SE ELIMINÓ el 2026-08-11.
    //
    // Era una SIMULACIÓN vendida como función del plan de pago: armaba el JSON, lo DESCARTABA
    // sin escribirlo en ningún sitio y respondía "Copia de seguridad subida a Google Drive con
    // éxito" con un md5() disfrazado de identificador de archivo. Su propio comentario lo
    // reconocía. En la pantalla, además, "Vincular Cuenta de Google" ni siquiera llamaba al
    // servidor: pintaba "Conectado", una cuenta de correo escrita a mano y dos archivos con
    // tamaños inventados. Nada de esto subía nada a ninguna nube.

}
