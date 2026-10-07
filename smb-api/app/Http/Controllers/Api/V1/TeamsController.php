<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sites.view');
        $request->validate(['site_id' => ['sometimes', 'integer', 'min:1']]);
        $query = Team::query()->with('site:id,name,code');

        if (! $request->user()->hasRole('SUPER_ADMIN')) {
            $query->whereIn('site_id', $request->user()->sites()->select('sites.id'));
        }

        if ($request->filled('site_id')) {
            $siteId = (int) $request->query('site_id');
            $this->assertSiteScope($request, $siteId);
            $query->where('site_id', $siteId);
        }

        return $this->paginated($query->orderBy('name')->paginate($this->perPage($request)), 'Daftar tim berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'teams.manage');
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertSiteScope($request, (int) $data['site_id']);
        $this->assertSiteActive((int) $data['site_id']);
        $this->validateUniqueName((int) $data['site_id'], $data['name']);

        $team = DB::transaction(function () use ($request, $data): Team {
            $team = Team::create($data);
            $this->audit($request, $team, 'team.created');

            return $team->refresh();
        });

        return $this->success($team->load('site:id,name,code'), 'Tim berhasil dibuat.', 201);
    }

    public function show(Request $request, Team $team): JsonResponse
    {
        $this->authorizePermission($request, 'sites.view');
        $this->assertSiteScope($request, $team->site_id);

        return $this->success($team->load('site:id,name,code')->loadCount('devices'), 'Detail tim berhasil diambil.');
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorizePermission($request, 'teams.manage');
        $this->assertSiteScope($request, $team->site_id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $this->validateUniqueName($team->site_id, $data['name'], $team->id);
        }

        DB::transaction(function () use ($request, $team, $data): void {
            $team->update($data);
            $this->audit($request, $team, 'team.updated', ['changed_fields' => array_keys($data)]);
        });

        return $this->success($team->refresh()->load('site:id,name,code'), 'Tim berhasil diperbarui.');
    }

    public function destroy(Request $request, Team $team): JsonResponse
    {
        $this->authorizePermission($request, 'teams.manage');
        $this->assertSiteScope($request, $team->site_id);

        if ($team->devices()->exists()) {
            return $this->conflict('Tim masih memiliki perangkat. Pindahkan atau arsipkan perangkat terlebih dahulu.');
        }

        DB::transaction(function () use ($request, $team): void {
            $team->delete();
            $this->audit($request, $team, 'team.deleted');
        });

        return $this->success(null, 'Tim berhasil dihapus.');
    }

    private function assertSiteActive(int $siteId): void
    {
        abort_if(! Site::whereKey($siteId)->where('is_active', true)->exists(), 422, 'Lokasi tidak aktif.');
    }

    private function validateUniqueName(int $siteId, string $name, ?int $ignoreId = null): void
    {
        $rule = Rule::unique('teams', 'name')->where('site_id', $siteId);
        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }
        validator(['name' => $name], ['name' => ['required', $rule]])->validate();
    }
}
