import paramiko
import os
import sys
import io

def run_cmd(ssh, cmd):
    print(f"\n>>> Running: {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_status = stdout.channel.recv_exit_status()
    print(f"Exit Status: {exit_status}")
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(f"Stdout:\n{out}")
    if err:
        print(f"Stderr:\n{err}")
    return exit_status

def main():
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')
    
    ip = "46.225.153.115"
    username = "root"
    key_path = os.path.expanduser("~/.ssh/id_rsa_py")
    private_key = paramiko.RSAKey.from_private_key_file(key_path)
    
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        ip, 
        username=username, 
        pkey=private_key, 
        timeout=15, 
        look_for_keys=False, 
        allow_agent=False
    )
    
    try:
        remote_script = """<?php
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== RAW USERS IN DB ===\\n";
$users = DB::table('users')->get();
foreach ($users as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Job Role ID: {$u->job_role_id} | Tenant ID: {$u->tenant_id} | Email: {$u->email}\\n";
}

echo "\\n=== RAW EMPLOYEES IN DB ===\\n";
$employees = DB::table('employees')->get();
foreach ($employees as $e) {
    echo "ID: {$e->id} | Name: {$e->name} | Job Role ID: {$e->job_role_id} | User ID: {$e->user_id}\\n";
}

echo "\\n=== RAW ACADEMY COURSES ===\\n";
$courses = DB::table('academy_courses')->get();
foreach ($courses as $c) {
    echo "Course ID: {$c->id} | Title: {$c->title} | Target Role ID: {$c->target_job_role_id} | Tenant ID: {$c->tenant_id} | Active: {$c->is_active}\\n";
}

echo "\\n=== JOB ROLES ===\\n";
$roles = DB::table('job_roles')->get();
foreach ($roles as $r) {
    echo "Role ID: {$r->id} | Name: {$r->name} | Tenant ID: {$r->tenant_id}\\n";
}
"""
        sftp = ssh.open_sftp()
        with sftp.file("/var/www/talent360/Backend/inspect_remote_db.php", "w") as f:
            f.write(remote_script)
        sftp.close()
        
        run_cmd(ssh, "docker exec talent360-backend php /var/www/inspect_remote_db.php")
        run_cmd(ssh, "rm -f /var/www/talent360/Backend/inspect_remote_db.php")
        
    except Exception as e:
        print(f"Error: {str(e)}")
    finally:
        ssh.close()

if __name__ == '__main__':
    main()
