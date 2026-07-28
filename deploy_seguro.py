#!/usr/bin/env python3
"""
Despliegue a Hetzner con RESPALDO PREVIO.

Reemplaza a deploy_to_hetzner.py, que migraba la base de datos sin copia de seguridad: si una
migración fallaba a mitad, no había a dónde volver. Aquí el respaldo es obligatorio y el despliegue
se aborta si no se puede confirmar.

Otras diferencias frente al script anterior:
  - Detecta la clave SSH disponible (el anterior exigía ~/.ssh/id_rsa_py, que puede no existir) y
    soporta claves Ed25519 además de RSA.
  - Comprueba que el remoto del SERVIDOR sea el mismo al que se hizo push. El servidor despliega
    con `git pull`, así que si apunta a otro repositorio el deploy "termina bien" sin traer nada.
  - Verifica la configuración de producción ANTES de migrar (APP_ENV, APP_DEBUG, caché) y ejecuta
    `reloj:preflight` al terminar.
  - Modo --dry-run para ensayar sin tocar nada.

Uso:
    python deploy_seguro.py --dry-run          # ensayo: no modifica nada
    python deploy_seguro.py                    # despliegue real
    python deploy_seguro.py -m "mensaje"       # con mensaje de commit propio
"""

import argparse
import os
import subprocess
import sys
from datetime import datetime

try:
    import paramiko
except ImportError:
    print("Falta paramiko. Instálalo con:  pip install paramiko")
    sys.exit(1)

# --- Configuración del servidor -------------------------------------------------------------
SERVIDOR_IP = "46.225.153.115"
SERVIDOR_USUARIO = "root"
RUTA_PROYECTO = "/var/www/talent360"
CONTENEDOR_BACKEND = "talent360-backend"
CONTENEDOR_DB = "talent360_postgres"
BD_NOMBRE = "talent360_saas"
BD_USUARIO = "postgres"
RUTA_RESPALDOS = "/var/backups/talent360"

# Repositorio al que se sube. El servidor DEBE tener este mismo remoto.
REPO_ESPERADO = "Adan717/Talent-360"

# Claves SSH candidatas, en orden de preferencia. La primera es la dedicada del
# servidor propio (Talent-360-V2 en Hetzner, generada 2026-07-28).
CLAVES_SSH = ["~/.ssh/talent360_v2", "~/.ssh/id_rsa_py", "~/.ssh/id_ed25519", "~/.ssh/id_rsa"]


class Abortar(Exception):
    """Detiene el despliegue con un motivo legible."""


def titulo(texto):
    print(f"\n{'=' * 70}\n  {texto}\n{'=' * 70}")


def paso(texto):
    print(f"\n>> {texto}")


def ok(texto):
    print(f"   OK   {texto}")


def aviso(texto):
    print(f"   AVISO  {texto}")


def cmd_local(comando, permitir_fallo=False):
    print(f"   $ {comando}")
    r = subprocess.run(comando, shell=True, capture_output=True, text=True)
    salida = (r.stdout or "").strip()
    if salida:
        print("   " + salida.replace("\n", "\n   "))
    if r.returncode != 0 and not permitir_fallo:
        raise Abortar(f"Falló el comando local: {comando}\n{(r.stderr or '').strip()}")
    return r.returncode == 0, salida


def cmd_remoto(ssh, comando, permitir_fallo=False):
    print(f"   [servidor] $ {comando}")
    _, stdout, stderr = ssh.exec_command(comando)
    codigo = stdout.channel.recv_exit_status()
    salida = stdout.read().decode(errors="replace").strip()
    error = stderr.read().decode(errors="replace").strip()
    if salida:
        print("   " + salida.replace("\n", "\n   "))
    if error and codigo != 0:
        print("   " + error.replace("\n", "\n   "))
    if codigo != 0 and not permitir_fallo:
        raise Abortar(f"Falló en el servidor: {comando}")
    return codigo == 0, salida


def conectar(ensayo):
    ruta_clave = None
    for candidata in CLAVES_SSH:
        expandida = os.path.expanduser(candidata)
        if os.path.exists(expandida):
            ruta_clave = expandida
            break
    if not ruta_clave:
        raise Abortar(
            "No se encontró ninguna clave SSH. Buscadas: " + ", ".join(CLAVES_SSH)
        )
    ok(f"Clave SSH encontrada: {ruta_clave}")

    if ensayo:
        aviso("Ensayo: no se abre la conexión SSH.")
        return None

    clave = None
    for tipo in (paramiko.Ed25519Key, paramiko.RSAKey):
        try:
            clave = tipo.from_private_key_file(ruta_clave)
            break
        except Exception:
            continue
    if clave is None:
        raise Abortar(
            f"No se pudo leer {ruta_clave}. Si tiene contraseña, cárgala en el agente SSH "
            "o genera una clave sin contraseña para el despliegue."
        )

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        SERVIDOR_IP,
        username=SERVIDOR_USUARIO,
        pkey=clave,
        timeout=15,
        look_for_keys=False,
        allow_agent=False,
    )
    ok(f"Conectado a {SERVIDOR_USUARIO}@{SERVIDOR_IP}")
    return ssh


def comprobaciones_locales(ensayo):
    titulo("FASE 1 · Comprobaciones locales")

    _, rama = cmd_local("git rev-parse --abbrev-ref HEAD")
    if rama != "main":
        raise Abortar(f"Estás en la rama '{rama}'. El despliegue se hace desde 'main'.")
    ok(f"Rama: {rama}")

    _, remotos = cmd_local("git remote -v", permitir_fallo=True)
    if REPO_ESPERADO not in remotos:
        raise Abortar(
            f"El remoto no apunta a {REPO_ESPERADO}. Configúralo con:\n"
            f"    git remote add origin git@github.com:{REPO_ESPERADO}.git"
        )
    ok(f"Remoto correcto ({REPO_ESPERADO})")

    _, pendientes = cmd_local("git status --porcelain", permitir_fallo=True)
    if pendientes:
        aviso("Hay cambios sin confirmar; se incluirán en el commit del despliegue.")


def subir_codigo(mensaje, ensayo):
    titulo("FASE 2 · Subir el código a GitHub")
    if ensayo:
        aviso("Ensayo: no se hace commit ni push.")
        return

    _, pendientes = cmd_local("git status --porcelain", permitir_fallo=True)
    if pendientes:
        cmd_local("git add -A")
        cmd_local(f'git commit -m "{mensaje}"', permitir_fallo=True)
    cmd_local("git push origin main")
    ok("Código subido a GitHub")


def respaldar_base(ssh, ensayo):
    """Respaldo OBLIGATORIO. Si no se confirma, el despliegue se aborta."""
    titulo("FASE 3 · RESPALDO de la base de datos (obligatorio)")

    sello = datetime.now().strftime("%Y-%m-%d_%H%M%S")
    archivo = f"{RUTA_RESPALDOS}/talent360_{sello}.sql"

    if ensayo:
        aviso(f"Ensayo: se respaldaría en {archivo}")
        return archivo

    cmd_remoto(ssh, f"mkdir -p {RUTA_RESPALDOS}")
    cmd_remoto(
        ssh,
        f"docker exec {CONTENEDOR_DB} pg_dump -U {BD_USUARIO} {BD_NOMBRE} > {archivo}",
    )

    # Un pg_dump puede "terminar bien" y dejar un archivo vacío si el contenedor no respondió.
    _, tamano = cmd_remoto(ssh, f"stat -c %s {archivo} 2>/dev/null || echo 0")
    try:
        bytes_respaldo = int(tamano.strip())
    except ValueError:
        bytes_respaldo = 0

    if bytes_respaldo < 1024:
        raise Abortar(
            f"El respaldo quedó vacío o es demasiado pequeño ({bytes_respaldo} bytes). "
            "NO se continúa: sin copia de seguridad no se migra una base con nómina."
        )

    ok(f"Respaldo confirmado: {archivo} ({bytes_respaldo:,} bytes)")
    cmd_remoto(ssh, f"ls -lht {RUTA_RESPALDOS} | head -5", permitir_fallo=True)
    return archivo


def revisar_configuracion(ssh, ensayo):
    titulo("FASE 4 · Configuración de producción (antes de migrar)")
    if ensayo:
        aviso("Ensayo: no se revisa el .env del servidor.")
        return

    ruta_env = f"{RUTA_PROYECTO}/Backend/.env"
    for clave, esperado, motivo in [
        ("APP_ENV", "production", "de esto dependen los gates de hora simulada, suplantación y reseteo de QA"),
        ("APP_DEBUG", "false", "en true, cada error 500 expone credenciales de base de datos"),
    ]:
        _, valor = cmd_remoto(
            ssh, f"grep -E '^{clave}=' {ruta_env} | head -1 || echo '{clave}=(ausente)'",
            permitir_fallo=True,
        )
        actual = valor.split("=", 1)[1].strip() if "=" in valor else "(ausente)"
        if actual.lower() != esperado:
            raise Abortar(
                f"{clave} = {actual} en el servidor; debe ser '{esperado}' ({motivo}).\n"
                f"    Edítalo en {ruta_env} y vuelve a ejecutar."
            )
        ok(f"{clave} = {actual}")

    _, cache = cmd_remoto(
        ssh, f"grep -E '^CACHE_STORE=' {ruta_env} | head -1 || echo 'CACHE_STORE=(ausente)'",
        permitir_fallo=True,
    )
    if "redis" in cache.lower():
        raise Abortar(
            "CACHE_STORE=redis, pero el docker-compose no levanta ningún servicio de Redis: "
            "la aplicación fallará con 'Class \"Redis\" not found' en el primer login. "
            "Cámbialo a CACHE_STORE=file o añade Redis al compose."
        )
    ok(f"Caché: {cache.split('=', 1)[-1].strip() or 'por defecto'}")


def actualizar_servidor(ssh, ensayo):
    titulo("FASE 5 · Actualizar el código en el servidor")
    if ensayo:
        aviso("Ensayo: no se actualiza el servidor.")
        return

    _, remoto_servidor = cmd_remoto(
        ssh, f"cd {RUTA_PROYECTO} && git remote get-url origin", permitir_fallo=True
    )
    if REPO_ESPERADO not in remoto_servidor:
        raise Abortar(
            f"El servidor apunta a '{remoto_servidor}', no a {REPO_ESPERADO}.\n"
            f"    Haría 'git pull' de otro repositorio y el despliegue no traería tus cambios.\n"
            f"    Corrígelo en el servidor con:\n"
            f"      cd {RUTA_PROYECTO} && git remote set-url origin git@github.com:{REPO_ESPERADO}.git"
        )
    ok(f"El servidor apunta al repositorio correcto")

    cmd_remoto(ssh, f"cd {RUTA_PROYECTO} && git pull")
    cmd_remoto(
        ssh,
        f"chmod -R 777 {RUTA_PROYECTO}/Backend/storage {RUTA_PROYECTO}/Backend/bootstrap/cache",
        permitir_fallo=True,
    )


def migrar(ssh, archivo_respaldo, ensayo):
    titulo("FASE 6 · Migraciones")
    if ensayo:
        aviso("Ensayo: no se migra.")
        return

    exito, _ = cmd_remoto(
        ssh,
        f"docker exec -u www-data {CONTENEDOR_BACKEND} php artisan migrate --force",
        permitir_fallo=True,
    )
    if not exito:
        raise Abortar(
            "Las migraciones fallaron. La base puede haber quedado a medias.\n"
            f"    Restaura con:\n"
            f"      docker exec -i {CONTENEDOR_DB} psql -U {BD_USUARIO} {BD_NOMBRE} < {archivo_respaldo}"
        )
    ok("Migraciones aplicadas")


def reiniciar(ssh, ensayo):
    titulo("FASE 7 · Recargar servicios")
    if ensayo:
        aviso("Ensayo: no se reinician servicios.")
        return

    for c in ["config:clear", "cache:clear"]:
        cmd_remoto(ssh, f"docker exec -u www-data {CONTENEDOR_BACKEND} php artisan {c}", permitir_fallo=True)
    cmd_remoto(ssh, f"cd {RUTA_PROYECTO} && docker compose restart backend backend-web reverb")
    cmd_remoto(ssh, f"cd {RUTA_PROYECTO} && docker compose up -d --build frontend")
    cmd_remoto(
        ssh,
        f"docker exec {CONTENEDOR_BACKEND} chmod -R 777 /var/www/storage /var/www/bootstrap/cache",
        permitir_fallo=True,
    )


def programar_tareas(ssh, ensayo):
    """Scheduler de Laravel + drenado de la cola, vía cron del HOST (idempotente).

    Sin esto NADA de lo agendado corre en producción: ni tasks:flag-unfinished
    (tareas inconclusas), ni payroll:calculate-weekly (pre-nómina diaria), ni la
    purga de chat. Y con QUEUE_CONNECTION=database, los jobs (LogTaskValidationJob,
    eventos de websocket) se encolan y nadie los procesa.

    El drenado usa `queue:work --stop-when-empty` cada minuto con flock: patrón
    ligero suficiente para este tamaño de servidor, sin systemd/supervisor.
    """
    titulo("FASE 8 · Scheduler y cola (cron del host)")
    if ensayo:
        aviso("Ensayo: no se instala el cron.")
        return

    crons = [
        (
            "artisan schedule:run",
            f"* * * * * docker exec -u www-data {CONTENEDOR_BACKEND} php artisan schedule:run "
            ">> /var/log/talent360-schedule.log 2>&1",
        ),
        (
            "artisan queue:work",
            f"* * * * * flock -n /tmp/talent360-queue.lock docker exec -u www-data {CONTENEDOR_BACKEND} "
            "php artisan queue:work --stop-when-empty --max-time=50 --tries=3 "
            ">> /var/log/talent360-queue.log 2>&1",
        ),
    ]

    for marca, linea in crons:
        ya_instalado, _ = cmd_remoto(
            ssh, f"crontab -l 2>/dev/null | grep -qF '{marca}'", permitir_fallo=True
        )
        if ya_instalado:
            ok(f"cron ya instalado: {marca}")
        else:
            cmd_remoto(ssh, f'(crontab -l 2>/dev/null; echo "{linea}") | crontab -')
            ok(f"cron instalado: {marca}")

    # Confirmación: el scheduler debe listar las tareas sin tronar.
    cmd_remoto(
        ssh,
        f"docker exec {CONTENEDOR_BACKEND} php artisan schedule:list",
        permitir_fallo=True,
    )


def verificar(ssh, ensayo):
    titulo("FASE 9 · Verificación posterior")
    if ensayo:
        aviso("Ensayo: no se verifica.")
        return

    exito, _ = cmd_remoto(
        ssh, f"docker exec {CONTENEDOR_BACKEND} php artisan reloj:preflight", permitir_fallo=True
    )
    if not exito:
        aviso("El preflight reportó fallos. Revisa la salida de arriba ANTES de dejar entrar gente.")
    else:
        ok("Preflight sin fallos críticos")


def main():
    parser = argparse.ArgumentParser(description="Despliegue a Hetzner con respaldo previo")
    parser.add_argument("-m", "--mensaje", default=None, help="Mensaje del commit de despliegue")
    parser.add_argument("--dry-run", action="store_true", help="Ensayo: no modifica nada")
    args = parser.parse_args()

    ensayo = args.dry_run
    mensaje = args.mensaje or f"Despliegue {datetime.now():%Y-%m-%d %H:%M}"

    if ensayo:
        print("\n*** MODO ENSAYO: no se modifica nada ***")

    ssh = None
    try:
        comprobaciones_locales(ensayo)
        subir_codigo(mensaje, ensayo)

        titulo("Conexión con el servidor")
        ssh = conectar(ensayo)

        archivo_respaldo = respaldar_base(ssh, ensayo)
        revisar_configuracion(ssh, ensayo)
        actualizar_servidor(ssh, ensayo)
        migrar(ssh, archivo_respaldo, ensayo)
        reiniciar(ssh, ensayo)
        programar_tareas(ssh, ensayo)
        verificar(ssh, ensayo)

        titulo("Despliegue completado")
        if not ensayo:
            print(f"  Respaldo previo: {archivo_respaldo}")
            print("  Haz una comprobación manual: entra como colaborador, ficha una entrada")
            print("  y confirma que aparece en el monitor.")
        return 0

    except Abortar as e:
        titulo("DESPLIEGUE ABORTADO")
        print(f"  {e}")
        return 1
    except Exception as e:
        titulo("ERROR INESPERADO")
        print(f"  {type(e).__name__}: {e}")
        return 1
    finally:
        if ssh:
            ssh.close()


if __name__ == "__main__":
    sys.exit(main())
