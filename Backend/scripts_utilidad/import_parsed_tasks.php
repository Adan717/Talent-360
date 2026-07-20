<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Task;
use Illuminate\Support\Facades\DB;

$jsonPath = __DIR__ . '/../tasks_import.json';
if (!file_exists($jsonPath)) {
    echo "ERROR: JSON file not found at " . $jsonPath . "\n";
    exit(1);
}

$tasks = json_decode(file_get_contents($jsonPath), true);
if (!$tasks) {
    echo "ERROR: Invalid JSON structure.\n";
    exit(1);
}

$tenantId = 1; // DecorArte
DB::beginTransaction();

try {
    echo "Importing " . count($tasks) . " tasks into Database...\n";
    foreach ($tasks as $task) {
        $taskData = [
            'title' => $task['title'],
            'estimated_mins' => $task['estimatedMins'],
            'priority' => $task['priority'],
            'category' => $task['category'],
            'target_type' => $task['targetType'],
            'target_id' => $task['targetId'],
            'assistant_type' => $task['assistantType'],
            'assistant_prompt' => $task['assistantPrompt'],
            'is_auto_capture' => $task['isAutoCapture'],
            'validation_mode' => $task['validationMode'],
            'can_be_done_sitting' => $task['canBeDoneSitting'],
            'description' => $task['description'],
            'validation_criteria' => $task['validationCriteria'],
            'frequency' => $task['frequency'],
            'evidence_type' => $task['evidenceType'],
            'procedure_steps' => $task['procedureSteps'],
            'is_validated' => $task['isValidated'],
            'tenant_id' => $tenantId,
        ];
        
        $existing = Task::withoutGlobalScopes()
            ->where('id', $task['id'])
            ->where('tenant_id', $tenantId)
            ->first();
            
        if ($existing) {
            $existing->update($taskData);
            echo "Updated Task #" . $task['id'] . ": " . $task['title'] . "\n";
        } else {
            $taskData['id'] = $task['id'];
            Task::create($taskData);
            echo "Created Task #" . $task['id'] . ": " . $task['title'] . "\n";
        }
    }
    
    DB::commit();
    echo "SUCCESS: Database Sincronizada con 53 tareas de Obsidian!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR during import: " . $e->getMessage() . "\n";
    exit(1);
}
