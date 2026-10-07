$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspaceRoot = Split-Path -Parent $PSScriptRoot
$rootEnvPath = Join-Path $workspaceRoot '.env'
$apiEnvPath = Join-Path $workspaceRoot 'smb-api/.env'
$rootExamplePath = Join-Path $workspaceRoot '.env.example'
$apiExamplePath = Join-Path $workspaceRoot 'smb-api/.env.example'

if ((Test-Path -LiteralPath $rootEnvPath) -or (Test-Path -LiteralPath $apiEnvPath)) {
    throw 'Refusing to replace existing .env files. Back them up and configure them manually, or run this script from a clean checkout.'
}

function New-RandomBase64Key {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($bytes)
}

function New-RandomHexSecret {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant())
}

function Set-EnvValue([string[]] $Lines, [string] $Name, [string] $Value) {
    $pattern = '^' + [Regex]::Escape($Name) + '=.*$'
    $matches = @($Lines | Where-Object { $_ -match $pattern })
    if ($matches.Count -ne 1) { throw "Expected exactly one $Name entry in the example environment file." }
    return ,@($Lines | ForEach-Object { if ($_ -match $pattern) { "$Name=$Value" } else { $_ } })
}

$postgresPassword = New-RandomHexSecret
$redisPassword = New-RandomHexSecret
$appKey = New-RandomBase64Key
$gatewayAppKey = New-RandomBase64Key
$gatewaySecret = New-RandomHexSecret

$rootLines = [IO.File]::ReadAllLines($rootExamplePath, [Text.Encoding]::UTF8)
foreach ($entry in @(
    @{ Name = 'POSTGRES_PASSWORD'; Value = $postgresPassword },
    @{ Name = 'REDIS_PASSWORD'; Value = $redisPassword },
    @{ Name = 'APP_KEY'; Value = $appKey },
    @{ Name = 'GATEWAY_SHARED_SECRET'; Value = $gatewaySecret },
    @{ Name = 'GATEWAY_APP_KEY'; Value = $gatewayAppKey },
    @{ Name = 'WS_ALLOWED_ORIGINS'; Value = 'http://localhost:5173,http://localhost:8080' }
)) { $rootLines = Set-EnvValue $rootLines $entry.Name $entry.Value }

$apiLines = [IO.File]::ReadAllLines($apiExamplePath, [Text.Encoding]::UTF8)
foreach ($entry in @(
    @{ Name = 'APP_ENV'; Value = 'local' },
    @{ Name = 'APP_KEY'; Value = $appKey },
    @{ Name = 'APP_DEBUG'; Value = 'true' },
    @{ Name = 'DB_PASSWORD'; Value = $postgresPassword },
    @{ Name = 'REDIS_PASSWORD'; Value = $redisPassword },
    @{ Name = 'GATEWAY_SHARED_SECRET'; Value = $gatewaySecret }
)) { $apiLines = Set-EnvValue $apiLines $entry.Name $entry.Value }

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[IO.File]::WriteAllLines($rootEnvPath, $rootLines, $utf8NoBom)
[IO.File]::WriteAllLines($apiEnvPath, $apiLines, $utf8NoBom)

Write-Host 'Created local-only .env files with independent random database, Redis, Laravel, and gateway secrets.'
Write-Host 'Values were not printed. Keep both files private; they are ignored by Git.'
