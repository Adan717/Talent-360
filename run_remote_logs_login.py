import paramiko
import os
import sys
import io

def run_cmd(ssh, cmd):
    print(f"\n>>> Running: {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_status = stdout.channel.recv_exit_status()
    print(f"Exit Status: {exit_status}")
    out = stdout.read().decode('utf-8').strip()
    err = stderr.read().decode('utf-8').strip()
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
        run_cmd(ssh, "docker logs --tail 25 talent360-backend-web")
        run_cmd(ssh, "docker logs --tail 25 talent360-backend")
    finally:
        ssh.close()

if __name__ == "__main__":
    main()
