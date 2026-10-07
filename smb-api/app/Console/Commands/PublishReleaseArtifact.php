<?php

namespace App\Console\Commands;

use App\Models\ReleaseArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PublishReleaseArtifact extends Command
{
    protected $signature = 'smb:publish-release
        {file : APK path verified with Android apksigner before publication}
        {app : SMB-Lacak or SMB-Master}
        {version : Release version such as 1.0.0}
        {version_code : Monotonic positive Android version code}
        {signer_sha256 : Verified APK signer certificate SHA-256}
        {--minimum-android-api=26 : Minimum supported Android API}';

    protected $description = 'Publish an apksigner-verified Android APK to private storage with integrity metadata.';

    public function handle(): int
    {
        $source = realpath((string) $this->argument('file'));
        $app = (string) $this->argument('app');
        $version = (string) $this->argument('version');
        $versionCode = filter_var($this->argument('version_code'), FILTER_VALIDATE_INT);
        $signer = strtolower((string) $this->argument('signer_sha256'));
        $minimumAndroidApi = (int) $this->option('minimum-android-api');
        $expectedSigner = (string) config('smb.release_certificate_sha256');

        if (! in_array($app, ['SMB-Lacak', 'SMB-Master'], true)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $version) !== 1
            || $versionCode === false || $versionCode < 1
            || $minimumAndroidApi < 26 || $minimumAndroidApi > 36
            || preg_match('/\A[a-f0-9]{64}\z/', $signer) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedSigner) !== 1
            || ! hash_equals($expectedSigner, $signer)) {
            $this->error('Metadata release tidak valid atau fingerprint tidak cocok dengan SMB_RELEASE_CERT_SHA256.');

            return self::FAILURE;
        }

        if ($source === false || ! is_file($source) || ! is_readable($source)
            || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'apk'
            || filesize($source) < 1 || filesize($source) > 300 * 1024 * 1024) {
            $this->error('APK tidak tersedia, tidak terbaca, atau melewati batas ukuran 300 MB.');

            return self::FAILURE;
        }

        $checksum = hash_file('sha256', $source);
        $releaseTime = now();
        $relativePath = sprintf('releases/%s/%d-%s.apk', strtolower($app), $versionCode, $checksum);
        $disk = Storage::disk(config('filesystems.default'));
        if (! $disk->exists($relativePath)) {
            $stream = fopen($source, 'rb');
            if ($stream === false) {
                $this->error('File APK gagal dibuka.');

                return self::FAILURE;
            }

            try {
                if (! $disk->put($relativePath, $stream)) {
                    $this->error('Storage menolak penulisan file rilis.');

                    return self::FAILURE;
                }
            } finally {
                fclose($stream);
            }
        }

        $storedStream = $disk->readStream($relativePath);
        if (! is_resource($storedStream)) {
            $this->error('Storage menolak pembacaan file rilis setelah ditulis.');

            return self::FAILURE;
        }
        try {
            $storedHashContext = hash_init('sha256');
            hash_update_stream($storedHashContext, $storedStream);
            $storedChecksum = hash_final($storedHashContext);
        } finally {
            fclose($storedStream);
        }
        if (! hash_equals($checksum, $storedChecksum)) {
            $this->error('Checksum file di storage tidak cocok dengan APK sumber.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($app, $version, $versionCode, $minimumAndroidApi, $signer, $checksum, $releaseTime, $relativePath): void {
                ReleaseArtifact::query()->create([
                    'app_name' => $app,
                    'version' => $version,
                    'version_code' => $versionCode,
                    'minimum_android_api' => $minimumAndroidApi,
                    'released_at' => $releaseTime,
                    'download_path' => $relativePath,
                    'checksum_sha256' => $checksum,
                    'signing_certificate_sha256' => $signer,
                    'signature_verified_at' => $releaseTime,
                    'is_active' => true,
                ]);

                activity('release-publish')
                    ->withProperties([
                        'app_name' => $app,
                        'version' => $version,
                        'version_code' => $versionCode,
                        'checksum_sha256' => $checksum,
                        'signing_certificate_sha256' => $signer,
                    ])
                    ->log('release.published');
            });
        } catch (\Throwable $exception) {
            if (! ReleaseArtifact::query()->where('download_path', $relativePath)->exists()) {
                $disk->delete($relativePath);
            }
            report($exception);
            $this->error('Metadata gagal disimpan. Periksa apakah versi atau version code sudah pernah digunakan.');

            return self::FAILURE;
        }

        $this->info("Published {$app} {$version} ({$versionCode})");
        $this->line("SHA-256: {$checksum}");
        $this->line("Signer SHA-256: {$signer}");
        $this->line("Storage: {$relativePath}");

        return self::SUCCESS;
    }
}
