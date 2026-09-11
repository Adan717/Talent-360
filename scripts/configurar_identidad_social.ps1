[CmdletBinding()]
param([string]$ApplePrivateKeyPath = '')
$ErrorActionPreference = 'Stop'
function B64([string]$value) { [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($value)) }
$google = (Read-Host 'Google OAuth Web Client ID (termina en .apps.googleusercontent.com)').Trim().Trim('"')
if ($google -notmatch '^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$') {
    throw 'Ese valor no es el ID de cliente OAuth de una aplicacion web. No uses API key ni Client Secret; debe terminar en .apps.googleusercontent.com.'
}
$apple = (Read-Host 'Apple Services ID (Enter para dejar Apple pendiente)').Trim().Trim('"')
$team = ''; $key = ''; $p8 = ''
if ($apple) {
    $team = (Read-Host 'Apple Team ID').Trim().Trim('"')
    $key = (Read-Host 'Apple Key ID').Trim().Trim('"')
    if (-not $ApplePrivateKeyPath) { $ApplePrivateKeyPath = Read-Host 'Ruta completa al archivo Apple .p8' }
    $resolved = (Resolve-Path -LiteralPath $ApplePrivateKeyPath).Path
    $p8 = [IO.File]::ReadAllText($resolved)
}
$payload = @((B64 $google), (B64 $apple), (B64 $team), (B64 $key), (B64 $p8)) -join "`n"
$payload | & ssh.exe -i "$env:USERPROFILE\.ssh\talent360_v2" -o IdentitiesOnly=yes -o BatchMode=yes root@46.225.153.115 "sed 's/\r$//' /var/www/talent360-v2/scripts/configurar_identidad_social.sh | sh"
if ($LASTEXITCODE -ne 0) { throw 'El servidor rechazo la configuracion. Revisa el mensaje inmediatamente anterior.' }
$config = Invoke-RestMethod -Uri 'https://talent360.com.mx/api/v1/auth/social/config' -TimeoutSec 20
if (-not $config.google_client_id) { throw 'El servidor respondio, pero Google todavia no aparece habilitado.' }
Write-Host 'Google habilitado y confirmado por la aplicacion.' -ForegroundColor Green
