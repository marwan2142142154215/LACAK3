<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceNetworkViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NetworkViolationsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sites.view');
        $filters = $request->validate([
            'site_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['OPEN', 'RESOLVED'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DeviceNetworkViolation::query()->with([
            'device:id,name,site_id,network_type,last_seen_at',
            'site:id,name,code',
        ]);
        if (! $request->user()->hasRole('SUPER_ADMIN')) {
            $query->whereIn('site_id', $request->user()->sites()->select('sites.id'));
        }
        if (isset($filters['site_id'])) {
            $this->assertSiteScope($request, (int) $filters['site_id']);
            $query->where('site_id', $filters['site_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $this->paginated($query->orderByDesc('last_seen_at')->paginate($this->perPage($request)), 'Daftar pelanggaran jaringan berhasil diambil.');
    }
}
