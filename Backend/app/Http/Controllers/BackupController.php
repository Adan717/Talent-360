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
    private $tables = [
        'companies',
        'job_roles',
        'role_clock_policies',
        'users',
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
        $backupData = [];

        foreach ($this->tables as $table) {
            $backupData[$table] = DB::table($table)->where('tenant_id', $tenantId)->get()->toArray();
        }

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

            // Disable foreign key checks temporarily to wipe tables cleanly
            if (config('database.default') === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF;');
            } else {
                DB::statement('SET CONSTRAINTS ALL DEFERRED;');
            }

            // Clean existing tenant records in reverse dependency order
            $tablesToClean = array_reverse($this->tables);
            foreach ($tablesToClean as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }

            // Restore in correct dependency order
            foreach ($this->tables as $table) {
                if (isset($data[$table]) && is_array($data[$table])) {
                    foreach ($data[$table] as $row) {
                        $rowArray = (array)$row;
                        // Force tenant isolation (security block)
                        $rowArray['tenant_id'] = $tenantId;
                        
                        DB::table($table)->insert($rowArray);
                    }
                }
            }

            if (config('database.default') === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }

            DB::commit();

            return response()->json(['message' => 'Respaldo restaurado con éxito.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Import Error',
                'message' => 'Ocurrió un error al importar los datos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simulate Google Drive sync
     */
    public function googleSync(Request $request)
    {
        $user = $request->user();
        if (!$this->checkPremiumAccess($user)) {
            return response()->json([
                'error' => 'Premium Feature Locked',
                'message' => 'La sincronización con Google Drive es una característica exclusiva de los planes de pago.'
            ], 403);
        }

        $request->validate([
            'google_token' => 'required|string'
        ]);

        // In a production app, we would use the Google API PHP Client here.
        // For our simulated environment, we will mock the Google Drive API response 
        // to return a successful sync status and a simulated File ID.
        
        $tenantId = $user->tenant_id;
        $backupData = [];

        foreach ($this->tables as $table) {
            $backupData[$table] = DB::table($table)->where('tenant_id', $tenantId)->get()->toArray();
        }

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

        $jsonStr = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonStr, config('app.key'));
        $payload['_signature'] = $signature;

        // Mock upload ID
        $mockFileId = 'gdrive_' . md5(now() . $tenantId);

        return response()->json([
            'status' => 'success',
            'message' => 'Copia de seguridad subida a Google Drive con éxito.',
            'file_id' => $mockFileId,
            'filename' => 'talent360_backup_' . date('Y-m-d') . '.json',
            'synced_at' => now()->toIso8601String()
        ]);
    }
}
