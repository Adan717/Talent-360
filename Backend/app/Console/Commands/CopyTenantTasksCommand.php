<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TenantTaskClonerService;

class CopyTenantTasksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:copy-tasks
                            {--from=1 : ID o nombre del tenant origen (por defecto DecorArte 360 / 1)}
                            {--to=DecorArte SA de CV : ID o nombre del tenant destino}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clona las tareas, rutinas y puestos de un tenant origen hacia un tenant destino.';

    /**
     * Execute the console command.
     */
    public function handle(TenantTaskClonerService $clonerService): int
    {
        $from = $this->option('from') ?: 1;
        $to = $this->option('to') ?: 'DecorArte SA de CV';

        $this->info("Iniciando clonación de tareas y rutinas desde [{$from}] hacia [{$to}]...");

        try {
            $result = $clonerService->cloneTasksAndRoutines($from, $to);

            $this->info("✅ Clonación completada exitosamente:");
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Tenant Origen', "{$result['source_tenant_name']} (ID: {$result['source_tenant_id']})"],
                    ['Tenant Destino', "{$result['target_tenant_name']} (ID: {$result['target_tenant_id']})"],
                    ['Puestos Mapeados/Creados', $result['roles_mapped']],
                    ['Tareas Clonadas', $result['tasks_cloned']],
                    ['Rutinas Clonadas', $result['routines_cloned']],
                    ['Relaciones Pivote (routine_task)', $result['routine_task_relations']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Error durante la clonación: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
