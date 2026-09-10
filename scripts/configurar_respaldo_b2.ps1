[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
function B64([string]$value) { [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($value)) }
function Plain([Security.SecureString]$secure) {
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}
$endpoint = (Read-Host 'Endpoint B2, sin https://').Trim()
$bucket = (Read-Host 'Nombre del bucket privado').Trim()
$keyId = (Read-Host 'Application Key ID').Trim()
$appKey = Plain (Read-Host 'Application Key (no se mostrará)' -AsSecureString)
$password = Plain (Read-Host 'Contraseña nueva para cifrar el respaldo (guárdala fuera del servidor)' -AsSecureString)
$payload = @((B64 $endpoint),(B64 $bucket),(B64 $keyId),(B64 $appKey),(B64 $password)) -join "`n"
$payload | & ssh.exe -i "$env:USERPROFILE\.ssh\talent360_v2" -o IdentitiesOnly=yes -o BatchMode=yes root@46.225.153.115 "sed 's/\r$//' /var/www/talent360-v2/scripts/configurar_respaldo_b2.sh | sh"
if ($LASTEXITCODE -ne 0) { throw 'No se pudo configurar el respaldo externo.' }
