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
        # Check active containers on remote
        run_cmd(ssh, "docker ps")
        
        # Check remote .env content
        run_cmd(ssh, "cat /var/www/talent360/Backend/.env | grep -E 'DB_|APP_'")
        
    except Exception as e:
        print(f"Error: {str(e)}")
    finally:
        ssh.close()

if __name__ == '__main__':
    main()
