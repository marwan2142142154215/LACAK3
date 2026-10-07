<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceRegistrationCode;
use App\Models\ReleaseArtifact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReleasesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $artifacts = ReleaseArtifact::query()->where('is_active', true)
            ->whereNotNull('signature_verified_at')
            ->whereNotNull('signing_certificate_sha256')
            ->orderBy('app_name')->orderByDesc('released_at')
            // Keep the private path selected for the integrity check. The model
            // hides it from JSON serialization, so this does not expose storage paths.
            ->get(['id', 'app_name', 'version', 'version_code', 'minimum_android_api', 'released_at', 'download_path', 'checksum_sha256', 'signing_certificate_sha256', 'signature_verified_at'])
            ->filter(function (ReleaseArtifact $artifact): bool {
                try {
                    $this->assertArtifactIsAvailable($artifact);

                    return true;
                } catch (Throwable $exception) {
                    Log::warning('Release artifact failed its publication access check.', [
                        'release_id' => $artifact->id,
                        'reason' => $exception->getMessage(),
                    ]);

                    return false;
                }
            })->values();

        return $this->success($artifacts, 'Katalog rilis berhasil diambil.');
    }

    public function download(Request $request, ReleaseArtifact $releaseArtifact): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        abort_unless($releaseArtifact->is_active, 404, 'Rilis tidak ditemukan.');

        $validated = $request->validate([
            'registration_code_id' => ['sometimes', 'required', 'uuid'],
        ]);
        $registrationCode = null;
        abort_if($this->isTrackerRelease($releaseArtifact) && ! isset($validated['registration_code_id']), 422, 'SMB Lacak harus diunduh bersama sesi registrasi Site/Team.');
        if (isset($validated['registration_code_id'])) {
            $this->authorizePermission($request, 'devices.create');
            abort_unless($this->isTrackerRelease($releaseArtifact), 422, 'Kode registrasi hanya dapat dipasangkan dengan SMB Lacak.');
            $registrationCode = DeviceRegistrationCode::query()
                ->with(['site:id,name,code,is_active', 'team:id,site_id,name,is_active'])
                ->findOrFail($validated['registration_code_id']);
            $this->assertSiteScope($request, $registrationCode->site_id);
            abort_unless($this->registrationCodeIsAvailable($registrationCode), 409, 'Kode registrasi tidak lagi dapat digunakan. Buat kode baru.');
        }

        try {
            $this->assertArtifactIsAvailable($releaseArtifact);
            $expiresAt = now()->addMinutes(5);
            if ($registrationCode && $registrationCode->expires_at->lt($expiresAt)) {
                $expiresAt = $registrationCode->expires_at;
            }
            $routeParameters = [
                'releaseArtifact' => $releaseArtifact->getRouteKey(),
                'actor' => $request->user()->getKey(),
            ];
            if ($registrationCode) {
                $routeParameters['registrationCode'] = $registrationCode->id;
            }
            $url = URL::temporarySignedRoute('api.v1.releases.file', $expiresAt, $routeParameters);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Penyimpanan rilis tidak siap; unduhan tidak dapat dibuat.', 503);
        }

        $auditProperties = ['expires_in_seconds' => max(0, now()->diffInSeconds($expiresAt, false))];
        if ($registrationCode) {
            $auditProperties += [
                'site_id' => $registrationCode->site_id,
                'team_id' => $registrationCode->team_id,
                'registration_session_id' => $registrationCode->id,
            ];
        }
        activity('release-download')->causedBy($request->user())->performedOn($releaseArtifact)
            ->withProperties($auditProperties)->log('release.download_url_created');

        return $this->success([
            'url' => $url,
            'expires_at' => $expiresAt->toISOString(),
            'registration_session_id' => $registrationCode?->id,
        ], 'Tautan unduhan sementara dibuat.');
    }

    public function file(ReleaseArtifact $releaseArtifact, int $actor, ?string $registrationCode = null): Response
    {
        abort_unless($releaseArtifact->is_active, 404, 'Rilis tidak ditemukan.');
        $user = User::query()->find($actor);
        abort_unless($user && $user->is_active && $user->can('devices.view'), 403, 'Akun tidak lagi diizinkan mengunduh rilis.');
        abort_if($this->isTrackerRelease($releaseArtifact) && $registrationCode === null, 403, 'Unduhan SMB Lacak memerlukan sesi registrasi Site/Team.');
        $code = null;
        if ($registrationCode !== null) {
            abort_unless($user->can('devices.create'), 403, 'Akun tidak lagi diizinkan membuat paket registrasi.');
            abort_unless($this->isTrackerRelease($releaseArtifact), 422, 'Kode registrasi hanya dapat dipasangkan dengan SMB Lacak.');
            $code = DeviceRegistrationCode::query()->findOrFail($registrationCode);
            abort_if(! $user->hasRole('SUPER_ADMIN') && ! $user->sites()->whereKey($code->site_id)->exists(), 404, 'Data tidak ditemukan.');
            abort_unless($this->registrationCodeIsAvailable($code), 410, 'Kode registrasi tidak lagi dapat digunakan.');
        }

        try {
            $this->assertArtifactIsAvailable($releaseArtifact);
            $response = Storage::disk(config('filesystems.default'))->download(
                $releaseArtifact->download_path,
                $this->downloadName($releaseArtifact),
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            );
            $auditProperties = ['delivery' => 'signed_local_or_configured_disk'];
            if ($code) {
                $auditProperties += [
                    'site_id' => $code->site_id,
                    'team_id' => $code->team_id,
                    'registration_session_id' => $code->id,
                ];
            }
            activity('release-download')->causedBy($user)->performedOn($releaseArtifact)
                ->withProperties($auditProperties)->log('release.download_stream_started');

            return $response;
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Berkas rilis gagal divalidasi atau dibaca dari storage.', 503);
        }
    }

    private function isTrackerRelease(ReleaseArtifact $artifact): bool
    {
        return Str::of($artifact->app_name)->lower()->replaceMatches('/[^a-z0-9]/', '')->value() === 'smblacak';
    }

    private function registrationCodeIsAvailable(DeviceRegistrationCode $code): bool
    {
        return $code->consumed_at === null
            && $code->revoked_at === null
            && $code->expires_at->isFuture()
            && $code->attempt_count < 5
            && $code->site !== null
            && $code->site->is_active
            && $code->team !== null
            && $code->team->is_active
            && $code->team->site_id === $code->site_id;
    }

    private function assertArtifactIsAvailable(ReleaseArtifact $artifact): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        if ($artifact->signature_verified_at === null
            || preg_match('/\A[a-f0-9]{64}\z/i', $artifact->signing_certificate_sha256 ?? '') !== 1
            || ! $disk->exists($artifact->download_path)
            || preg_match('/\A[a-f0-9]{64}\z/i', $artifact->checksum_sha256) !== 1) {
            throw new \RuntimeException('Release artifact is missing or has invalid metadata.');
        }

        $stream = $disk->readStream($artifact->download_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Release artifact could not be opened.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $actualHash = hash_final($context);
        } finally {
            fclose($stream);
        }

        if (! hash_equals(strtolower($artifact->checksum_sha256), $actualHash)) {
            throw new \RuntimeException('Release artifact checksum mismatch.');
        }
    }

    private function downloadName(ReleaseArtifact $artifact): string
    {
        $app = preg_replace('/[^A-Za-z0-9._-]/', '-', $artifact->app_name) ?: 'SMB';
        $version = preg_replace('/[^A-Za-z0-9._-]/', '-', $artifact->version) ?: 'release';

        return "{$app}-{$version}.apk";
    }
}
