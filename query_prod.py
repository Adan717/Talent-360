import paramiko
import os
import base64

key_path = os.path.expanduser('~/.ssh/id_rsa_py')
private_key = paramiko.RSAKey.from_private_key_file(key_path)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('46.225.153.115', username='root', pkey=private_key)

php_code = """
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

echo "=== TENANT 33 TASKS COUNT ===\n";
echo DB::table('tasks')->where('tenant_id', 33)->count() . "\n";

echo "=== TENANT 33 ROUTINES COUNT ===\n";
echo DB::table('routines')->where('tenant_id', 33)->count() . "\n";

echo "=== TENANT 33 ROUTINE_TASK COUNT ===\n";
$routineIds = DB::table('routines')->where('tenant_id', 33)->pluck('id');
echo DB::table('routine_task')->whereIn('routine_id', $routineIds)->count() . "\n";
"""

b64 = base64.b64encode(php_code.encode('utf-8')).decode('utf-8')
cmd = f"docker exec talent360-backend php -r \"eval(base64_decode('{b64}'));\""
stdin, stdout, stderr = ssh.exec_command(cmd)
print("STDOUT:\n" + stdout.read().decode('utf-8'))
print("STDERR:\n" + stderr.read().decode('utf-8'))
ssh.close()
