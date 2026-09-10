#!/bin/sh
# Configura Restic + Backblaze B2 por STDIN. No recibe ni imprime secretos en argumentos.
set -eu
umask 077
[ "$(id -u)" -eq 0 ] || { echo 'Se requiere root.' >&2; exit 1; }
decode() { printf '%s' "$1" | base64 -d; }
IFS= read -r endpoint_b64; IFS= read -r bucket_b64; IFS= read -r id_b64
IFS= read -r appkey_b64; IFS= read -r password_b64
endpoint=$(decode "$endpoint_b64"); bucket=$(decode "$bucket_b64")
keyid=$(decode "$id_b64"); appkey=$(decode "$appkey_b64")
case "$endpoint" in s3.*.backblazeb2.com) ;; *) echo 'Endpoint B2 inválido.' >&2; exit 1;; esac
case "$bucket" in *[!A-Za-z0-9.-]*|'') echo 'Bucket inválido.' >&2; exit 1;; esac
[ -n "$keyid" ] && [ -n "$appkey" ] || { echo 'Faltan credenciales B2.' >&2; exit 1; }
case "$keyid$appkey" in *[!A-Za-z0-9]*) echo 'Credenciales B2 inválidas.' >&2; exit 1;; esac
[ -n "$password_b64" ] || { echo 'Falta la contraseña de cifrado.' >&2; exit 1; }
command -v restic >/dev/null 2>&1 || { apt-get update -qq; apt-get install -y -qq restic >/dev/null; }
install -d -m 700 -o root -g root /etc/talent360-backup
decode "$password_b64" > /etc/talent360-backup/restic-password.tmp
printf '\n' >> /etc/talent360-backup/restic-password.tmp
chmod 600 /etc/talent360-backup/restic-password.tmp
mv /etc/talent360-backup/restic-password.tmp /etc/talent360-backup/restic-password
env_tmp=$(mktemp /etc/talent360-backup/restic.env.XXXXXX)
{
  printf "export AWS_ACCESS_KEY_ID='%s'\n" "$keyid"
  printf "export AWS_SECRET_ACCESS_KEY='%s'\n" "$appkey"
  printf "export RESTIC_REPOSITORY='s3:https://%s/%s'\n" "$endpoint" "$bucket"
  printf "export RESTIC_PASSWORD_FILE='/etc/talent360-backup/restic-password'\n"
} > "$env_tmp"
chmod 600 "$env_tmp"; mv "$env_tmp" /etc/talent360-backup/restic.env
. /etc/talent360-backup/restic.env
if ! restic snapshots >/dev/null 2>&1; then restic init >/dev/null; fi
/usr/local/bin/respaldo-talent360 >/dev/null
restic snapshots --latest 1 >/dev/null
echo 'Copia externa cifrada configurada y primer snapshot verificado.'
