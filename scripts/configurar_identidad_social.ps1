[CmdletBinding()]
param([string]$ApplePrivateKeyPath = '')
$ErrorActionPreference = 'Stop'
function B64([string]$value) { [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($value)) }
$google = Read-Host 'Google Web Client ID'
$apple = Read-Host 'Apple Services ID (Enter para dejar Apple pendiente)'
$team = ''; $key = ''; $p8 = ''
if ($apple) {
    $team = Read-Host 'Apple Team ID'
    $key = Read-Host 'Apple Key ID'
    if (-not $ApplePrivateKeyPath) { $ApplePrivateKeyPath = Read-Host 'Ruta completa al archivo Apple .p8' }
    $resolved = (Resolve-Path -LiteralPath $ApplePrivateKeyPath).Path
    $p8 = [IO.File]::ReadAllText($resolved)
}
$payload = @((B64 $google), (B64 $apple), (B64 $team), (B64 $key), (B64 $p8)) -join "`n"
$payload | & ssh.exe -i "$env:USERPROFILE\.ssh\talent360_v2" -o IdentitiesOnly=yes -o BatchMode=yes root@46.225.153.115 "sed 's/\r$//' /var/www/talent360-v2/scripts/configurar_identidad_social.sh | sh"
if ($LASTEXITCODE -ne 0) { throw 'El servidor rechazó la configuración.' }
