[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string] $ApkPath,

    [Parameter(Mandatory = $true)]
    [ValidateSet('SMB-Lacak', 'SMB-Master')]
    [string] $AppName,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$')]
    [string] $Version,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int] $VersionCode,

    [ValidateRange(26, 36)]
    [int] $MinimumAndroidApi = 26
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$sdkRoot = if ($env:ANDROID_SDK_ROOT) { $env:ANDROID_SDK_ROOT } elseif ($env:ANDROID_HOME) { $env:ANDROID_HOME } else { Join-Path $env:LOCALAPPDATA 'Android\Sdk' }
$apksigner = Join-Path $sdkRoot 'build-tools\36.0.0\apksigner.bat'
$aapt = Join-Path $sdkRoot 'build-tools\36.0.0\aapt.exe'
$fingerprintFile = Join-Path $repoRoot '.secrets\android\certificate-sha256.txt'

if (-not (Test-Path -LiteralPath $apksigner -PathType Leaf)) {
    throw 'Android Build Tools 36.0.0 apksigner was not found.'
}
if (-not (Test-Path -LiteralPath $aapt -PathType Leaf)) {
    throw 'Android Build Tools 36.0.0 aapt was not found.'
}
if (-not (Test-Path -LiteralPath $fingerprintFile -PathType Leaf)) {
    throw 'The locally pinned SMB release certificate fingerprint is missing.'
}

$absoluteApk = (Resolve-Path -LiteralPath $ApkPath).Path
$verification = @(& $apksigner verify --verbose --print-certs $absoluteApk 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw 'apksigner rejected the APK. The release was not published.'
}
$verificationText = $verification -join [Environment]::NewLine
$match = [regex]::Match($verificationText, 'Signer #1 certificate SHA-256 digest:\s*([A-Fa-f0-9]{64})')
if (-not $match.Success) {
    throw 'apksigner did not report a signer certificate fingerprint.'
}
$signerFingerprint = $match.Groups[1].Value.ToLowerInvariant()
$pinnedFingerprint = [System.IO.File]::ReadAllText($fingerprintFile).Trim().ToLowerInvariant()
if ($signerFingerprint -cne $pinnedFingerprint) {
    throw 'The APK signer does not match the locally pinned SMB release certificate.'
}
$badging = @(& $aapt dump badging $absoluteApk 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw 'Android manifest metadata could not be read from the APK.'
}
$badgingText = $badging -join [Environment]::NewLine
$expectedPackage = if ($AppName -eq 'SMB-Lacak') { 'com.smb.lacak' } else { 'com.smb.master' }
$packageMatch = [regex]::Match($badgingText, "package: name='([^']+)' versionCode='([0-9]+)' versionName='([^']+)'")
$minimumMatch = [regex]::Match($badgingText, "sdkVersion:'([0-9]+)'")
$targetMatch = [regex]::Match($badgingText, "targetSdkVersion:'([0-9]+)'")
if (-not $packageMatch.Success -or
    $packageMatch.Groups[1].Value -cne $expectedPackage -or
    [int] $packageMatch.Groups[2].Value -ne $VersionCode -or
    $packageMatch.Groups[3].Value -cne $Version -or
    -not $minimumMatch.Success -or
    [int] $minimumMatch.Groups[1].Value -ne $MinimumAndroidApi -or
    -not $targetMatch.Success -or
    [int] $targetMatch.Groups[1].Value -ne 36) {
    throw 'APK application ID, version, minSdk or targetSdk does not match the release metadata.'
}

$stagingName = 'smb-release-' + [guid]::NewGuid().ToString('N') + '.apk'
$containerPath = '/var/www/html/storage/app/private/incoming/' + $stagingName
$compose = @('--project-directory', $repoRoot)

try {
    & docker compose @compose exec -T api php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'API database migration failed; release publication was stopped.' }

    & docker compose @compose exec -T api mkdir -p /var/www/html/storage/app/private/incoming
    if ($LASTEXITCODE -ne 0) { throw 'API private storage staging directory could not be prepared.' }

    & docker compose @compose cp $absoluteApk ('api:' + $containerPath)
    if ($LASTEXITCODE -ne 0) { throw 'APK copy to private server storage failed.' }

    & docker compose @compose exec -T api php artisan smb:publish-release $containerPath $AppName $Version $VersionCode $signerFingerprint --minimum-android-api=$MinimumAndroidApi
    if ($LASTEXITCODE -ne 0) { throw 'The server rejected the release metadata or storage write.' }
}
finally {
    & docker compose @compose exec -T api rm -f $containerPath 2>$null
}
