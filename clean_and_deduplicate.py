import paramiko
import os
import base64
import sys

sys.stdout.reconfigure(encoding='utf-8')

key_path = os.path.expanduser('~/.ssh/id_rsa_py')
private_key = paramiko.RSAKey.from_private_key_file(key_path)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('46.225.153.115', username='root', pkey=private_key)

php_code = """
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

DB::transaction(function() {
    echo "=== 1. DEPURANDO DUPLICADOS EN TENANT 1 (DecorArte 360) ===\n";
    $tasksT1 = DB::table('tasks')->where('tenant_id', 1)->orderBy('id', 'asc')->get();
    $seenTitles = [];
    $toDeleteTaskIds = [];
    $keptTaskIds = [];

    foreach ($tasksT1 as $t) {
        $normalized = trim(mb_strtolower($t->title));
        if (isset($seenTitles[$normalized])) {
            $toDeleteTaskIds[] = $t->id;
        } else {
            $seenTitles[$normalized] = $t->id;
            $keptTaskIds[] = $t->id;
        }
    }

    echo "Tenant 1: Guardando " . count($keptTaskIds) . " tareas únicas y borrando " . count($toDeleteTaskIds) . " duplicados...\n";

    if (!empty($toDeleteTaskIds)) {
        DB::table('routine_task')->whereIn('task_id', $toDeleteTaskIds)->delete();
        DB::table('task_assignments')->whereIn('task_id', $toDeleteTaskIds)->whereNotIn('status', ['completed', 'in_progress'])->delete();
        DB::table('tasks')->whereIn('id', $toDeleteTaskIds)->delete();
    }

    // Depurar rutinas duplicadas en Tenant 1
    $routinesT1 = DB::table('routines')->where('tenant_id', 1)->orderBy('id', 'asc')->get();
    $seenRoutines = [];
    $toDeleteRoutineIds = [];

    foreach ($routinesT1 as $r) {
        $key = trim(mb_strtolower($r->title)) . '_' . $r->target_role_id;
        if (isset($seenRoutines[$key])) {
            $toDeleteRoutineIds[] = $r->id;
        } else {
            $seenRoutines[$key] = $r->id;
        }
    }

    echo "Tenant 1: Guardando " . count($seenRoutines) . " rutinas únicas y borrando " . count($toDeleteRoutineIds) . " duplicadas...\n";

    if (!empty($toDeleteRoutineIds)) {
        DB::table('routine_task')->whereIn('routine_id', $toDeleteRoutineIds)->delete();
        DB::table('routines')->whereIn('id', $toDeleteRoutineIds)->delete();
    }

    echo "\n=== 2. EJECUTANDO CLONADO LIMPIO DE TENANT 1 A TENANT 33 ===\n";
    $service = new \App\Services\TenantTaskClonerService();
    $res = $service->cloneTasksAndRoutines(1, 33);
    print_r($res);
});

echo "\n=== RECONTEO FINAL PRODUCCION ===\n";
echo "Tenant 1 Tareas: " . DB::table('tasks')->where('tenant_id', 1)->count() . "\n";
echo "Tenant 1 Rutinas: " . DB::table('routines')->where('tenant_id', 1)->count() . "\n";
echo "Tenant 33 Tareas: " . DB::table('tasks')->where('tenant_id', 33)->count() . "\n";
echo "Tenant 33 Rutinas: " . DB::table('routines')->where('tenant_id', 33)->count() . "\n";
"""

b64 = base64.b64encode(php_code.encode('utf-8')).decode('utf-8')
cmd = f"docker exec talent360-backend php -r \"eval(base64_decode('{b64}'));\""
stdin, stdout, stderr = ssh.exec_command(cmd)
print("STDOUT:\n" + stdout.read().decode('utf-8', errors='replace'))
print("STDERR:\n" + stderr.read().decode('utf-8', errors='replace'))
ssh.close()
