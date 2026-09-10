# Copia interina fuera de Hetzner. Windows PowerShell 5.1 o PowerShell 7.
# No sobrescribe una copia válida con una descarga parcial; no imprime datos ni secretos.
[CmdletBinding()]
param(
    [string]$Destination = 'C:\Users\adanc\Respaldos-Talent360',
    [string]$IdentityFile = 'C:\Users\adanc\.ssh\talent360_v2',
    [string]$Server = 'root@46.225.153.115'
)
$ErrorActionPreference = 'Stop'
$mutex = New-Object Threading.Mutex($false, 'Local\Talent360BackupPull')
$locked = $false
try {
    $locked = $mutex.WaitOne(0)
    if (-not $locked) { throw 'Otra descarga está en curso.' }
    $folder = [IO.Path]::GetFullPath((Join-Path $Destination 'verificados'))
    New-Item -ItemType Directory -Force -Path $folder | Out-Null
    $sshArgs = @('-i', $IdentityFile, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3')
    $lines = @(& ssh.exe @sshArgs $Server 'cat /root/respaldos/auto/latest.sha256')
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 4) { throw 'No se recibió un manifiesto completo del servidor.' }
    $files = @()
    foreach ($line in $lines) {
        if ($line -notmatch '^([a-f0-9]{64})\s+((v2|prod)_(db|files)_([0-9]{8}_[0-9]{6})\.(dump|tar\.gz))$') { throw 'Manifiesto inválido; no se descargó nada.' }
        $files += [pscustomobject]@{ Hash = $Matches[1]; Name = $Matches[2]; Stamp = $Matches[5] }
    }
    if (($files.Stamp | Select-Object -Unique).Count -ne 1 -or ($files.Name | Select-Object -Unique).Count -ne 4) { throw 'Lote inconsistente.' }
    foreach ($file in $files) {
        $target = Join-Path $folder $file.Name
        if ((Test-Path -LiteralPath $target) -and (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -eq $file.Hash) { continue }
        $partial = "$target.partial"
        & scp.exe @sshArgs "${Server}:/root/respaldos/auto/$($file.Name)" $partial
        if ($LASTEXITCODE -ne 0) { throw "Descarga incompleta: $($file.Name). Se conserva como .partial." }
        if ((Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash -ne $file.Hash) { throw "SHA256 incorrecto: $($file.Name). No se publica como respaldo." }
        Move-Item -LiteralPath $partial -Destination $target -Force
    }
    $manifest = Join-Path $folder "manifest_$($files[0].Stamp).sha256"
    [IO.File]::WriteAllLines($manifest, $lines, [Text.Encoding]::ASCII)
    $receipt = @{ completed_utc = [DateTime]::UtcNow.ToString('o'); server = $Server; manifest = $manifest; files = 4; sha256_verified = $true } | ConvertTo-Json
    [IO.File]::WriteAllText((Join-Path $folder 'ultimo-verificado.json'), $receipt, [Text.Encoding]::UTF8)
    Write-Output 'Respaldo completo descargado: cuatro archivos verificados por SHA256.'
} catch {
    Write-Error $_
    exit 1
} finally {
    if ($locked) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
