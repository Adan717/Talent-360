import paramiko
import os
import sys
import io
import subprocess

def run_local_cmd(cmd):
    print(f"\n[Local] Running: {cmd}", flush=True)
    result = subprocess.run(cmd, shell=True, text=True, capture_output=True)
    if result.returncode != 0:
        print(f"[Local] Error executing command: {cmd}", flush=True)
        print(f"Stdout:\n{result.stdout}", flush=True)
        print(f"Stderr:\n{result.stderr}", flush=True)
        return False
    print("[Local] Success.", flush=True)
    return True

def run_remote_cmd(ssh, cmd):
    print(f"\n[Remote] Running: {cmd}", flush=True)
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_status = stdout.channel.recv_exit_status()
    print(f"[Remote] Exit Status: {exit_status}", flush=True)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(f"Stdout:\n{out}", flush=True)
    if err:
        print(f"Stderr:\n{err}", flush=True)
    return exit_status == 0

def main():
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')
    
    commit_msg = "Auto-deploy updates"
    if sys.stdin and sys.stdin.isatty():
        try:
            user_input = input("Enter commit message (default: 'Auto-deploy updates'): ").strip()
            if user_input:
                commit_msg = user_input
        except Exception:
            pass
        
    print(f"Using commit message: {commit_msg}", flush=True)
    print("\n--- STEP 1: Pushing changes to GitHub ---", flush=True)
    if not run_local_cmd("git add ."):
        return
    # We allow git commit to fail if there are no changes to commit
    subprocess.run(f'git commit -m "{commit_msg}"', shell=True)
    if not run_local_cmd("git push origin main"):
        print("Failed to push changes to GitHub. Aborting deployment.")
        return
        
    print("\n--- STEP 2: Connecting to Hetzner Server via SSH ---")
    ip = "46.225.153.115"
    username = "root"
    key_path = os.path.expanduser("~/.ssh/id_rsa_py")
    
    try:
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
        print("Connected successfully.")
        
        print("\n--- STEP 3: Updating repository on server ---")
        if not run_remote_cmd(ssh, "cd /var/www/talent360 && git pull"):
            print("Failed to pull latest changes on remote server.")
            return
            
        print("\n--- STEP 4: Running migrations and clearing cache (non-destructive) ---")
        # Fix permissions on storage and cache to prevent write lock issues
        run_remote_cmd(ssh, "chmod -R 777 /var/www/talent360/Backend/storage /var/www/talent360/Backend/bootstrap/cache")
        # Run migrations safely as www-data to prevent root ownership of logs/caches
        run_remote_cmd(ssh, "docker exec -u www-data talent360-backend php artisan migrate --force")
        # Run DecorArte database seeder as www-data (Commented out to prevent data loss in future deployments)
        # run_remote_cmd(ssh, "docker exec -u www-data talent360-backend php scripts_utilidad/seed_decorarte_final.php")
        # Clear config and cache as www-data
        run_remote_cmd(ssh, "docker exec -u www-data talent360-backend php artisan config:clear")
        run_remote_cmd(ssh, "docker exec -u www-data talent360-backend php artisan cache:clear")
        # Double check permissions after commands execution to prevent lockouts
        run_remote_cmd(ssh, "docker exec talent360-backend chmod -R 777 /var/www/storage /var/www/bootstrap/cache")
        run_remote_cmd(ssh, "chmod -R 777 /var/www/talent360/Backend/storage /var/www/talent360/Backend/bootstrap/cache")
        
        print("\n--- STEP 5: Rebuilding Frontend and restarting Backend containers ---")
        # Restart backend services to refresh mounts and reload code changes
        run_remote_cmd(ssh, "cd /var/www/talent360 && docker compose restart backend backend-web reverb")
        # Rebuild frontend image and recreate container to apply new assets
        run_remote_cmd(ssh, "cd /var/www/talent360 && docker compose up -d --build frontend")
        
        print("\nDeployment completed successfully!")
        
    except Exception as e:
        print(f"An error occurred during remote execution: {str(e)}")
    finally:
        try:
            ssh.close()
        except:
            pass

if __name__ == "__main__":
    main()
