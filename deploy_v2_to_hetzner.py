import paramiko
import os
import sys
import io

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

    print("=== DESPLIEGUE AUTOMÁTICO - TALENT 360 V2 (HETZNER) ===")
    ip = "46.225.153.115"
    username = "root"
    key_path = os.path.expanduser("~/.ssh/id_rsa_py")
    target_dir = "/var/www/talent360-v2"

    try:
        private_key = paramiko.RSAKey.from_private_key_file(key_path)
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(ip, username=username, pkey=private_key, timeout=15)
        print("Conectado exitosamente al servidor Hetzner.")

        print("\n--- PASO 1: Actualizando repositorio Talent 360 V2 ---")
        run_remote_cmd(ssh, f"cd {target_dir} && git checkout . && git pull origin main --rebase")

        print("\n--- PASO 2: Permisos y Migraciones ---")
        run_remote_cmd(ssh, f"chmod -R 777 {target_dir}/Backend/storage {target_dir}/Backend/bootstrap/cache")
        run_remote_cmd(ssh, "docker exec -u www-data talent360-v2-backend php artisan migrate --force")
        run_remote_cmd(ssh, "docker exec -u www-data talent360-v2-backend php artisan config:clear")
        run_remote_cmd(ssh, "docker exec -u www-data talent360-v2-backend php artisan cache:clear")

        print("\n--- PASO 3: Reiniciando contenedores V2 ---")
        run_remote_cmd(ssh, f"cd {target_dir} && docker compose -f docker-compose.v2.yml restart backend backend-web reverb")
        run_remote_cmd(ssh, f"cd {target_dir} && docker compose -f docker-compose.v2.yml up -d --build --force-recreate frontend")

        print("\n--- PASO 4: Estado de contenedores Talent 360 V2 ---")
        run_remote_cmd(ssh, "docker ps --filter 'name=talent360-v2'")

        print("\n¡Despliegue de Talent 360 V2 completado con éxito!")
    except Exception as e:
        print(f"Error durante el despliegue: {str(e)}")
    finally:
        try:
            ssh.close()
        except:
            pass

if __name__ == "__main__":
    main()
