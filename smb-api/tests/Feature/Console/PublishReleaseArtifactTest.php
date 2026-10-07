<?php

use App\Models\ReleaseArtifact;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'filesystems.default' => 'local',
        'smb.release_certificate_sha256' => str_repeat('e', 64),
    ]);
    Storage::fake('local');
});
function writeReleaseFixture(string $contents): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smb-release-'.Str::uuid().'.apk';
    file_put_contents($path, $contents);

    return $path;
}

it('publishes a pinned signed release only after the private storage file can be read and checksummed', function (): void {
    $source = writeReleaseFixture('apk fixture bytes');
    try {
        $this->artisan('smb:publish-release', [
            'file' => $source,
            'app' => 'SMB-Lacak',
            'version' => '1.1.0-beta.1',
            'version_code' => 2,
            'signer_sha256' => str_repeat('e', 64),
            '--minimum-android-api' => 26,
        ])->expectsOutputToContain('Published SMB-Lacak 1.1.0-beta.1 (2)')
            ->assertExitCode(0);
    } finally {
        @unlink($source);
    }

    $release = ReleaseArtifact::query()->sole();
    expect($release->is_active)->toBeTrue()
        ->and($release->minimum_android_api)->toBe(26)
        ->and($release->checksum_sha256)->toBe(hash('sha256', 'apk fixture bytes'))
        ->and($release->signing_certificate_sha256)->toBe(str_repeat('e', 64))
        ->and($release->signature_verified_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($release->download_path))->toBeTrue()
        ->and(DB::table('audit_logs')->where('description', 'release.published')->exists())->toBeTrue();
});

it('rejects releases with an unpinned signer, invalid version, or Android floor above API 36', function (): void {
    $source = writeReleaseFixture('apk fixture bytes');
    try {
        $this->artisan('smb:publish-release', [
            'file' => $source,
            'app' => 'SMB-Master',
            'version' => '1.1.0',
            'version_code' => 2,
            'signer_sha256' => str_repeat('f', 64),
        ])->expectsOutputToContain('fingerprint')
            ->assertExitCode(1);

        $this->artisan('smb:publish-release', [
            'file' => $source,
            'app' => 'SMB-Master',
            'version' => 'latest',
            'version_code' => 2,
            'signer_sha256' => str_repeat('e', 64),
            '--minimum-android-api' => 37,
        ])->expectsOutputToContain('fingerprint')
            ->assertExitCode(1);
    } finally {
        @unlink($source);
    }

    expect(ReleaseArtifact::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('releases'))->toBe([]);
});
