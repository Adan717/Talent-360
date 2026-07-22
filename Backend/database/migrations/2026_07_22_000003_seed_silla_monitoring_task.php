<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * §32: RelojVisual.tsx llama a startBreakWithSittingTask(9999) — un taskId
     * inventado sin registro real en `tasks`, que producía Task::find() === null.
     * Se crea una fila real por tenant para que el frontend pueda dejar de
     * hardcodear un id; expuesta en GET /sync/state como `silla_task_id`
     * (resuelta ahí en vez de vía system_settings, cuya PK es `key` sola — no
     * soporta un valor distinto por tenant, ver nota en BACKEND_INTERFACES.md §32).
     */
    public function up(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $exists = DB::table('tasks')
                ->where('tenant_id', $tenantId)
                ->where('title', 'Monitoreo de seguridad desde silla')
                ->exists();

            if (!$exists) {
                DB::table('tasks')->insert([
                    'tenant_id' => $tenantId,
                    'title' => 'Monitoreo de seguridad desde silla',
                    'estimated_mins' => 15,
                    'points' => 5,
                    'priority' => 'normal',
                    'category' => 'operativo',
                    'target_type' => 'role',
                    'validation_mode' => 'auto',
                    'can_be_done_sitting' => true,
                    'frequency' => 'Diaria',
                    'evidence_type' => 'Supervisión directa',
                    'is_auto_capture' => false,
                    'is_validated' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tasks')->where('title', 'Monitoreo de seguridad desde silla')->delete();
    }
};
