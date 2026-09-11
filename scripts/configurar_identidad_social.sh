#!/bin/sh
# Recibe por STDIN cinco valores base64 (Google, Apple client, team, key y .p8).
# Nunca acepta secretos en argumentos ni los imprime. Ejecutar como root en el servidor.
set -eu
umask 077
[ "$(id -u)" -eq 0 ] || { echo 'Se requiere root.' >&2; exit 1; }
RAIZ=/var/www/talent360-v2
ENV_FILE="$RAIZ/Backend/.env"
SECRETS=/etc/talent360-v2
decode() { printf '%s' "$1" | base64 -d; }
trim() { printf '%s' "$1" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'; }
IFS= read -r google_b64
IFS= read -r apple_b64
IFS= read -r team_b64
IFS= read -r key_b64
IFS= read -r p8_b64
# Windows PowerShell termina la entrada canalizada con CRLF. El CR no forma
# parte de las credenciales y haria parecer que el campo Apple vacio contiene datos.
google_b64=$(printf '%s' "$google_b64" | tr -d '\r')
apple_b64=$(printf '%s' "$apple_b64" | tr -d '\r')
team_b64=$(printf '%s' "$team_b64" | tr -d '\r')
key_b64=$(printf '%s' "$key_b64" | tr -d '\r')
p8_b64=$(printf '%s' "$p8_b64" | tr -d '\r')
google=$(trim "$(decode "$google_b64")")
apple=$(trim "$(decode "$apple_b64")")
team=$(trim "$(decode "$team_b64")")
key=$(trim "$(decode "$key_b64")")
case "$google" in *.apps.googleusercontent.com) ;; *) echo 'El Google OAuth Web Client ID debe terminar en .apps.googleusercontent.com.' >&2; exit 1;; esac
case "$google" in *[!A-Za-z0-9._-]*) echo 'Google Client ID invalido.' >&2; exit 1;; esac
if [ -n "$apple$team$key$p8_b64" ]; then
  [ -n "$apple" ] && [ -n "$team" ] && [ -n "$key" ] && [ -n "$p8_b64" ] || { echo 'La configuración Apple está incompleta.' >&2; exit 1; }
  case "$apple$team$key" in *[!A-Za-z0-9._-]*) echo 'Identificadores Apple inválidos.' >&2; exit 1;; esac
  decode "$p8_b64" | grep -q '^-----BEGIN PRIVATE KEY-----$' || { echo 'El archivo Apple .p8 no es válido.' >&2; exit 1; }
fi
if [ "${TALENT360_SOCIAL_DRY_RUN:-0}" = 1 ]; then
  echo 'Entradas de identidad validas.'
  exit 0
fi
if [ -n "$apple" ]; then
  install -d -m 750 -o root -g www-data "$SECRETS"
  p8_tmp=$(mktemp "$SECRETS/apple-signin.XXXXXX")
  decode "$p8_b64" > "$p8_tmp"
  chown root:www-data "$p8_tmp"; chmod 640 "$p8_tmp"
  mv "$p8_tmp" "$SECRETS/apple-signin.p8"
fi
set_value() {
  variable=$1; value=$2; tmp=$(mktemp "${ENV_FILE}.XXXXXX")
  grep -v "^${variable}=" "$ENV_FILE" > "$tmp" || true
  printf '%s=%s\n' "$variable" "$value" >> "$tmp"
  chown --reference="$ENV_FILE" "$tmp"; chmod --reference="$ENV_FILE" "$tmp"; mv "$tmp" "$ENV_FILE"
}
set_value GOOGLE_CLIENT_ID "$google"
if [ -n "$apple" ]; then
  set_value APPLE_CLIENT_ID "$apple"
  set_value APPLE_TEAM_ID "$team"
  set_value APPLE_KEY_ID "$key"
  set_value APPLE_PRIVATE_KEY_PATH /run/secrets/apple-signin.p8
  set_value APPLE_REDIRECT_URI https://talent360.com.mx/login
fi
cd "$RAIZ"
docker compose -f docker-compose.v2.yml up -d --force-recreate backend reverb >/dev/null
docker exec -u www-data talent360-v2-backend php artisan config:clear >/dev/null
docker restart talent360-v2-backend-web >/dev/null
echo 'Identidad social configurada. Los valores no se mostraron.'
