[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$ApiHealthUrl = 'http://127.0.0.1:8000/api/v1/health',
    [string]$WebHealthUrl = 'http://127.0.0.1:8080/healthz',
    [string]$WebSocketUrl = 'wss://ws.lacaksmbbot.com/ws',
    [int]$TimeoutSeconds = 8
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
}
$checks = [System.Collections.Generic.List[object]]::new()

function Add-Check {
    param(
        [string]$Name,
        [bool]$IsHealthy,
        [string]$Reason
    )

    $checks.Add([pscustomobject]@{
        Name = $Name
        IsHealthy = $IsHealthy
        Reason = $Reason
    })
}

Push-Location $ProjectRoot
$httpClient = [System.Net.Http.HttpClient]::new()
try {
    $dockerVersion = & docker info --format '{{.ServerVersion}}' 2>$null
    if ($LASTEXITCODE -eq 0 -and $dockerVersion) {
        Add-Check 'Docker Engine' $true "version $($dockerVersion.Trim())"
    } else {
        Add-Check 'Docker Engine' $false 'Docker Engine tidak merespons.'
    }

    $apiHealth = $null
    try {
        $apiHealth = Invoke-RestMethod -Method Get -Uri $ApiHealthUrl -TimeoutSec $TimeoutSeconds
    } catch {
        Add-Check 'Laravel' $false 'API health endpoint tidak dapat dihubungi.'
        foreach ($name in @('PostgreSQL', 'Redis', 'AdonisJS', 'WebSocket', 'Cloudflare Tunnel', 'Storage', 'Telegram')) {
            Add-Check $name $false 'Pemeriksaan gagal karena Laravel API tidak tersedia.'
        }
    }

    if ($null -ne $apiHealth) {
        $services = $apiHealth.data.services
        foreach ($name in @('Laravel', 'PostgreSQL', 'Redis', 'AdonisJS', 'WebSocket', 'Cloudflare Tunnel', 'Storage', 'Telegram')) {
            $service = $services.PSObject.Properties[$name].Value
            $status = if ($null -ne $service) { [string]$service.status } else { 'UNKNOWN' }
            $reason = if ($null -ne $service -and $service.reason) { [string]$service.reason } else { "status $status" }
            Add-Check $name ($status -eq 'OK') $reason
        }
    }

    try {
        $web = $httpClient.GetAsync($WebHealthUrl).GetAwaiter().GetResult()
        Add-Check 'Web' ([int]$web.StatusCode -eq 200) "HTTP $([int]$web.StatusCode)"
    } catch {
        Add-Check 'Web' $false 'Web health endpoint lokal tidak dapat dihubungi.'
    }

    $tunnelService = Get-Service -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match 'cloudflared|cloudflare' -or $_.DisplayName -match 'Cloudflare' } |
        Select-Object -First 1
    $tunnelRunning = $null -ne $tunnelService -and $tunnelService.Status -eq 'Running'
    if ($null -eq $apiHealth) {
        Add-Check 'Cloudflare Tunnel Service' $tunnelRunning $(if ($tunnelRunning) { 'service Running' } else { 'service Cloudflared tidak berjalan atau tidak ditemukan.' })
    } elseif (-not $tunnelRunning) {
        Add-Check 'Cloudflare Tunnel Service' $false 'service Cloudflared tidak berjalan atau tidak ditemukan.'
    } else {
        Add-Check 'Cloudflare Tunnel Service' $true "service $($tunnelService.Status), start type $($tunnelService.StartType)"
    }

    $socket = [System.Net.WebSockets.ClientWebSocket]::new()
    $cancellation = [System.Threading.CancellationTokenSource]::new([TimeSpan]::FromSeconds($TimeoutSeconds))
    try {
        $null = $socket.ConnectAsync([Uri]$WebSocketUrl, $cancellation.Token).GetAwaiter().GetResult()
        $isOpen = $socket.State -eq [System.Net.WebSockets.WebSocketState]::Open
        Add-Check 'WSS Public Endpoint' $isOpen $(if ($isOpen) { 'TLS and WebSocket upgrade succeeded; device authentication remains required.' } else { "connection state $($socket.State)" })
        if ($isOpen) {
            $null = $socket.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'SMB Doctor probe', $cancellation.Token).GetAwaiter().GetResult()
        }
    } catch {
        Add-Check 'WSS Public Endpoint' $false 'TLS/WebSocket connection to the public gateway failed.'
    } finally {
        $socket.Dispose()
        $cancellation.Dispose()
    }
} finally {
    $httpClient.Dispose()
    Pop-Location
}

foreach ($check in $checks) {
    $tag = if ($check.IsHealthy) { '[OK]' } else { '[FAIL]' }
    Write-Output "$tag $($check.Name): $($check.Reason)"
}

$failed = @($checks | Where-Object { -not $_.IsHealthy })
if ($failed.Count -eq 0) {
    Write-Output 'SYSTEM READY'
    exit 0
}

Write-Output "SYSTEM DEGRADED: $($failed.Count) pemeriksaan gagal."
exit 1
