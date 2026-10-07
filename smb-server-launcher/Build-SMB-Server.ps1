[CmdletBinding()]
param(
    [string]$Python = 'python'
)

$ErrorActionPreference = 'Stop'
$launcherDir = $PSScriptRoot
$projectRoot = Split-Path -Parent $launcherDir
$source = Join-Path $launcherDir 'smb_server_launcher.py'
$distDir = Join-Path $launcherDir 'dist'
$workDir = Join-Path $launcherDir '.pyinstaller-build'
$specDir = Join-Path $launcherDir '.pyinstaller-spec'

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'compose.yaml'))) {
    throw 'compose.yaml tidak ditemukan di root project SMB.'
}

& $Python -m PyInstaller --noconfirm --clean --onefile --console `
    --name 'SMB Server' `
    --distpath $distDir `
    --workpath $workDir `
    --specpath $specDir `
    $source

if ($LASTEXITCODE -ne 0) {
    throw 'Build SMB Server.exe gagal.'
}

Write-Output "Build selesai: $(Join-Path $distDir 'SMB Server.exe')"
