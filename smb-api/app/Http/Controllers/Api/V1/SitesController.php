<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SitesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sites.view');
        $query = Site::query();

        if (! $request->user()->hasRole('SUPER_ADMIN')) {
            $query->whereIn('id', $request->user()->sites()->select('sites.id'));
        }

        return $this->paginated($query->orderBy('name')->paginate($this->perPage($request)), 'Daftar lokasi berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sites.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:sites,code'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
            'allowed_wifi_ssids' => ['sometimes', 'array', 'max:32'],
            'allowed_wifi_ssids.*' => ['required', 'string', 'max:32', 'distinct'],
            'allowed_ip_ranges' => ['sometimes', 'array', 'max:64'],
            'allowed_ip_ranges.*' => ['required', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->validIpRange($value)) {
                    $fail('Masukkan IP atau CIDR yang valid.');
                }
            }],
        ]);

        $site = DB::transaction(function () use ($data, $request): Site {
            $site = Site::create($data);
            if (! $request->user()->hasRole('SUPER_ADMIN')) {
                $request->user()->sites()->syncWithoutDetaching([$site->id]);
            }

            $this->audit($request, $site, 'site.created');

            return $site->refresh();
        });

        return $this->success($site, 'Lokasi berhasil dibuat.', 201);
    }

    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorizePermission($request, 'sites.view');
        $this->assertSiteScope($request, $site->id);

        return $this->success($site->loadCount(['teams', 'devices']), 'Detail lokasi berhasil diambil.');
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorizePermission($request, 'sites.manage');
        $this->assertSiteScope($request, $site->id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'code' => ['sometimes', 'required', 'string', 'max:64', 'alpha_dash', Rule::unique('sites', 'code')->ignore($site->id)],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'allowed_wifi_ssids' => ['sometimes', 'array', 'max:32'],
            'allowed_wifi_ssids.*' => ['required', 'string', 'max:32', 'distinct'],
            'allowed_ip_ranges' => ['sometimes', 'array', 'max:64'],
            'allowed_ip_ranges.*' => ['required', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->validIpRange($value)) {
                    $fail('Masukkan IP atau CIDR yang valid.');
                }
            }],
        ]);

        DB::transaction(function () use ($request, $site, $data): void {
            $site->update($data);
            $this->audit($request, $site, 'site.updated', ['changed_fields' => array_keys($data)]);
        });

        return $this->success($site->refresh(), 'Lokasi berhasil diperbarui.');
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->authorizePermission($request, 'sites.manage');
        $this->assertSiteScope($request, $site->id);

        if ($site->teams()->exists() || $site->devices()->exists()) {
            return $this->conflict('Lokasi masih memiliki tim atau perangkat. Hapus atau pindahkan data terkait terlebih dahulu.');
        }

        DB::transaction(function () use ($request, $site): void {
            $site->delete();
            $this->audit($request, $site, 'site.deleted');
        });

        return $this->success(null, 'Lokasi berhasil dihapus.');
    }

    private function validIpRange(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $parts = explode('/', trim($value));
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (! isset($parts[1])) {
            return true;
        }
        if (! ctype_digit($parts[1])) {
            return false;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return (int) $parts[1] <= $max;
    }
}
