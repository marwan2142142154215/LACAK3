<?php

use App\Models\DeviceRegistrationCode;
use App\Models\ReleaseArtifact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function releaseDownloadArtifact(string $contents = 'signed debug fixture', string $appName = 'SMB-Master', string $version = '1.0.0', int $versionCode = 1): ReleaseArtifact
{
    $path = 'releases/'.strtolower(str_replace(' ', '-', $appName)).'-'.$version.'.apk';
    Storage::disk('local')->put($path, $contents);

    return ReleaseArtifact::query()->create([
        'app_name' => $appName,
        'version' => $version,
        'version_code' => $versionCode,
        'minimum_android_api' => 26,
        'released_at' => now(),
        'download_path' => $path,
        'checksum_sha256' => hash('sha256', $contents),
        'signing_certificate_sha256' => str_repeat('a', 64),
        'signature_verified_at' => now(),
        'is_active' => true,
    ]);
}

function releaseDownloadUser(): User
{
    $user = User::factory()->create();
    grantPermission($user, 'devices.view');

    return $user;
}

beforeEach(function (): void {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
});

it('creates a short-lived signed URL and streams the verified generic release from local storage', function (): void {
    $artifact = releaseDownloadArtifact();
    $user = releaseDownloadUser();

    $url = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download")
        ->assertOk()
        ->json('data.url');

    expect($url)->toBeString()->toContain('signature=');
    $download = $this->get($url)
        ->assertOk()
        ->assertDownload('SMB-Master-1.0.0.apk')
        ->assertStreamedContent('signed debug fixture');
    expect($download->headers->get('Cache-Control'))->toContain('no-store');

    expect(DB::table('audit_logs')->where('description', 'release.download_url_created')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('description', 'release.download_stream_started')->exists())->toBeTrue();
});

it('binds SMB Lacak downloads to an available server registration code and audits its site and team', function (): void {
    $actor = createSuperAdminUser();
    $site = createSite('Package site', 'package-site');
    $team = Team::query()->create(['site_id' => $site->id, 'name' => 'Package team', 'is_active' => true]);
    $code = createSmbRegistrationCode($actor, $site, $team);
    $artifact = releaseDownloadArtifact('signed debug fixture', 'SMB-Lacak');

    $url = $this->actingAs($actor, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download?registration_code_id={$code['id']}")
        ->assertOk()
        ->assertJsonPath('data.registration_session_id', $code['id'])
        ->json('data.url');

    $properties = DB::table('audit_logs')->where('description', 'release.download_url_created')->latest('id')->value('properties');
    $properties = is_string($properties) ? json_decode($properties, true, flags: JSON_THROW_ON_ERROR) : (array) $properties;
    expect($properties)->toMatchArray([
        'site_id' => $site->id,
        'team_id' => $team->id,
        'registration_session_id' => $code['id'],
    ]);

    $this->get($url)->assertOk()->assertDownload('SMB-Lacak-1.0.0.apk');
});

it('rejects enrollment downloads for invalid, expired, used, or non-tracker release codes', function (): void {
    $actor = createSuperAdminUser();
    $site = createSite('Package status site', 'package-status-site');
    $team = Team::query()->create(['site_id' => $site->id, 'name' => 'Package status team', 'is_active' => true]);
    $code = createSmbRegistrationCode($actor, $site, $team);
    $artifact = releaseDownloadArtifact('signed debug fixture', 'SMB-Lacak');

    $this->actingAs($actor, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download")
        ->assertUnprocessable();

    DeviceRegistrationCode::query()->whereKey($code['id'])->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($actor, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download?registration_code_id={$code['id']}")
        ->assertStatus(409);

    DeviceRegistrationCode::query()->whereKey($code['id'])->update(['expires_at' => now()->addMinutes(5), 'consumed_at' => now()]);
    $this->getJson("/api/v1/releases/{$artifact->id}/download?registration_code_id={$code['id']}")
        ->assertStatus(409);

    DeviceRegistrationCode::query()->whereKey($code['id'])->update(['consumed_at' => null]);
    $masterArtifact = ReleaseArtifact::query()->create([
        'app_name' => 'SMB-Master',
        'version' => '1.0.0',
        'version_code' => 1,
        'minimum_android_api' => 26,
        'released_at' => now(),
            'download_path' => 'releases/smb-master-1.0.0.apk',
            'checksum_sha256' => hash('sha256', 'master fixture'),
            'signing_certificate_sha256' => str_repeat('a', 64),
            'signature_verified_at' => now(),
            'is_active' => true,
    ]);
    Storage::disk('local')->put($masterArtifact->download_path, 'master fixture');
    $this->getJson("/api/v1/releases/{$masterArtifact->id}/download?registration_code_id={$code['id']}")
        ->assertUnprocessable();
});

it('requires device creation permission to attach an enrollment code to an APK download', function (): void {
    $actor = releaseDownloadUser();
    $site = createSite('Package permission site', 'package-permission-site');
    $team = Team::query()->create(['site_id' => $site->id, 'name' => 'Package permission team', 'is_active' => true]);
    $code = createSmbRegistrationCode(createSuperAdminUser(), $site, $team);
    $artifact = releaseDownloadArtifact('signed debug fixture', 'SMB-Lacak');

    $this->actingAs($actor, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download?registration_code_id={$code['id']}")
        ->assertForbidden();
});

it('rejects expired signed release URLs and corrupted artifacts', function (): void {
    $artifact = releaseDownloadArtifact('correct bytes');
    $user = releaseDownloadUser();
    $expiredUrl = URL::temporarySignedRoute('api.v1.releases.file', now()->subMinute(), [
        'releaseArtifact' => $artifact->id,
        'actor' => $user->id,
    ]);
    $this->get($expiredUrl)->assertForbidden();

    $url = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download")
        ->assertOk()
        ->json('data.url');
    Storage::disk('local')->put($artifact->download_path, 'tampered bytes');
    $this->get($url)->assertServiceUnavailable()->assertJsonPath('success', false);
});

it('does not issue a download URL without server permission', function (): void {
    $artifact = releaseDownloadArtifact();
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/releases/{$artifact->id}/download")
        ->assertForbidden();
});

it('shows only signed verified releases whose private storage is readable and checksum matches', function (): void {
    $verified = releaseDownloadArtifact('valid artifact');
    $unsigned = releaseDownloadArtifact('unsigned fixture', 'SMB-Lacak');
    $unsigned->forceFill([
        'signing_certificate_sha256' => null,
        'signature_verified_at' => null,
    ])->save();
    $corrupt = releaseDownloadArtifact('original bytes', 'SMB-Master', '2.0.0', 2);
    Storage::disk('local')->put($corrupt->download_path, 'changed bytes');
    $user = releaseDownloadUser();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/releases')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $verified->id)
        ->assertJsonPath('data.0.signing_certificate_sha256', str_repeat('a', 64));
});
