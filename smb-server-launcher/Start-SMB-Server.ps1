[CmdletBinding()]
param(
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location $projectRoot
try {
    if ($Stop) {
        & docker compose stop
        if ($LASTEXITCODE -ne 0) {
            Write-Error 'Shutdown gagal. Periksa Docker Compose dan log service.'
            exit 1
        }

        Write-Output 'SYSTEM STOPPED: container dihentikan dengan aman; volume data tetap disimpan.'
        exit 0
    }

    & docker compose up -d
    if ($LASTEXITCODE -ne 0) {
        Write-Error 'Startup gagal. Periksa Docker Engine, .env, dan output Compose.'
        exit 1
    }

    $tunnelService = Get-Service -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match 'cloudflared|cloudflare' -or $_.DisplayName -match 'Cloudflare' } |
        Select-Object -First 1
    if ($null -ne $tunnelService -and $tunnelService.Status -ne 'Running') {
        try {
            Start-Service -Name $tunnelService.Name
        } catch {
            Write-Warning 'Cloudflare Tunnel service belum dapat dimulai; status akan terlihat pada SMB Doctor.'
        }
    }

    & (Join-Path $PSScriptRoot 'SMB-Doctor.ps1') -ProjectRoot $projectRoot
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
