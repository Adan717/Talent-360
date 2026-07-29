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

echo "=== AUDITORIA DE TAREAS TENANT 1 (DecorArte 360) ===\n";
$t1Total = DB::table('tasks')->where('tenant_id', 1)->count();
$t1Unique = DB::table('tasks')->where('tenant_id', 1)->distinct('title')->count('title');
echo "Total tareas Tenant 1: {$t1Total}\n";
echo "Titulos unicos Tenant 1: {$t1Unique}\n\n";

echo "Desglose por titulo y fechas de creacion en Tenant 1:\n";
$t1Duplicates = DB::table('tasks')
    ->where('tenant_id', 1)
    ->select('title', DB::raw('count(*) as count'), DB::raw('MIN(created_at) as min_created'), DB::raw('MAX(created_at) as max_created'))
    ->groupBy('title')
    ->orderBy('count', 'desc')
    ->get();

foreach ($t1Duplicates->take(20) as $row) {
    echo "Count: {$row->count} | Title: {$row->title} | Creados entre: {$row->min_created} y {$row->max_created}\n";
}

echo "\n=== AUDITORIA DE TAREAS TENANT 33 (DecorArte S.A. de C.V.) ===\n";
$t33Total = DB::table('tasks')->where('tenant_id', 33)->count();
$t33Unique = DB::table('tasks')->where('tenant_id', 33)->distinct('title')->count('title');
echo "Total tareas Tenant 33: {$t33Total}\n";
echo "Titulos unicos Tenant 33: {$t33Unique}\n\n";

$t33Duplicates = DB::table('tasks')
    ->where('tenant_id', 33)
    ->select('title', DB::raw('count(*) as count'), DB::raw('MIN(created_at) as min_created'), DB::raw('MAX(created_at) as max_created'))
    ->groupBy('title')
    ->orderBy('count', 'desc')
    ->get();

foreach ($t33Duplicates->take(20) as $row) {
    echo "Count: {$row->count} | Title: {$row->title} | Creados entre: {$row->min_created} y {$row->max_created}\n";
}

echo "\n=== DESGLOSE DE CREACION DE TAREAS EN TENANT 1 POR FECHA ===\n";
$byDate = DB::table('tasks')
    ->where('tenant_id', 1)
    ->select(DB::raw('DATE(created_at) as date_created'), DB::raw('count(*) as count'))
    ->groupBy('date_created')
    ->orderBy('date_created', 'desc')
    ->get();

foreach ($byDate as $b) {
    echo "Fecha: {$b->date_created} | Tareas insertadas: {$b->count}\n";
}
"""

b64 = base64.b64encode(php_code.encode('utf-8')).decode('utf-8')
cmd = f"docker exec talent360-backend php -r \"eval(base64_decode('{b64}'));\""
stdin, stdout, stderr = ssh.exec_command(cmd)
print("STDOUT:\n" + stdout.read().decode('utf-8', errors='replace'))
print("STDERR:\n" + stderr.read().decode('utf-8', errors='replace'))
ssh.close()
