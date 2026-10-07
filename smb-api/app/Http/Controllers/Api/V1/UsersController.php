<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsersController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'users.manage');
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:160'],
            'site_id' => ['sometimes', 'integer', 'min:1', 'exists:sites,id'],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $query = $this->visibleUsersQuery($request)
            ->with(['roles:id,name', 'sites:id,name,code']);

        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $users) => $users
                ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                ->orWhereRaw('LOWER(username) LIKE LOWER(?)', [$term])
                ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]));
        }
        if (isset($filters['site_id'])) {
            $siteId = (int) $filters['site_id'];
            $this->assertSiteScope($request, $siteId);
            $query->whereHas('sites', fn (Builder $sites) => $sites->whereKey($siteId));
        }
        if (isset($filters['role'])) {
            $query->role($filters['role'], 'web');
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(fn (User $user): array => $this->userResource($user)));

        return $this->paginated($paginator, 'Daftar pengguna berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'users.manage');
        $request->merge([
            'username' => mb_strtolower(trim((string) $request->input('username', ''))),
            'email' => filled($request->input('email')) ? mb_strtolower(trim((string) $request->input('email'))) : null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'site_ids' => ['sometimes', 'array', 'max:100'],
            'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $roles = $data['roles'];
        $siteIds = array_values(array_unique(array_map('intval', $data['site_ids'] ?? [])));
        $this->assertAssignableRoles($request, $roles);
        $this->assertAssignedSites($request, $siteIds);
        $this->requireSiteScopeForRoles($roles, $siteIds);

        $user = DB::transaction(function () use ($data, $roles, $siteIds, $request): User {
            $this->lockUserAdministration();
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'username' => mb_strtolower(trim($data['username'])),
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'password_changed_at' => now(),
            ])->save();
            $user->syncRoles($roles);
            $user->sites()->sync($siteIds);

            $this->audit($request, $user, 'user.created', [
                'roles' => $roles,
                'site_ids' => $siteIds,
                'requires_two_factor_setup' => true,
            ]);

            return $user->load(['roles:id,name', 'sites:id,name,code']);
        });

        return $this->success($this->userResource($user), 'Pengguna dibuat. Login pertama harus menyelesaikan setup 2FA.', 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'users.manage');
        $this->assertUserScope($request, $user);

        return $this->success($this->userResource($user->load(['roles:id,name', 'sites:id,name,code'])), 'Detail pengguna berhasil diambil.');
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'users.manage');
        $this->assertUserScope($request, $user);
        if ($request->exists('username')) {
            $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
        }
        if ($request->exists('email')) {
            $request->merge(['email' => filled($request->input('email')) ? mb_strtolower(trim((string) $request->input('email'))) : null]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
            'roles' => ['sometimes', 'required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'site_ids' => ['sometimes', 'array', 'max:100'],
            'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $roles = array_key_exists('roles', $data) ? $data['roles'] : $user->getRoleNames()->all();
        $siteIds = array_key_exists('site_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['site_ids'])))
            : $user->sites()->pluck('sites.id')->map(fn ($id): int => (int) $id)->all();
        $isActive = (bool) ($data['is_active'] ?? $user->is_active);
        $this->assertAssignableRoles($request, $roles);
        $this->assertAssignedSites($request, $siteIds);
        $this->requireSiteScopeForRoles($roles, $siteIds);

        if ($user->is($request->user()) && ! $isActive) {
            return $this->conflict('Anda tidak dapat menonaktifkan akun sendiri.');
        }
        $actor = $request->user();
        $canSave = DB::transaction(function () use ($data, $roles, $siteIds, $isActive, $request, $user, $actor): bool {
            $this->lockUserAdministration();
            $user->refresh();
            if ($this->wouldRemoveLastActiveSuperAdmin($user, $roles, $isActive)) {
                return false;
            }

            $user->fill(collect($data)->only(['name', 'username', 'email'])->all());
            if (array_key_exists('email', $data)) {
                $user->email = $data['email'] === null ? null : mb_strtolower(trim($data['email']));
            }
            if (array_key_exists('username', $data)) {
                $user->username = mb_strtolower(trim($data['username']));
            }
            $user->is_active = $isActive;
            if (array_key_exists('password', $data)) {
                $user->password = Hash::make($data['password']);
                $user->password_changed_at = now();
            }
            $user->save();
            $user->syncRoles($roles);
            $user->sites()->sync($siteIds);

            $this->audit($request, $user, 'user.updated', [
                'changed_fields' => array_values(array_diff(array_keys($data), ['password', 'password_confirmation'])),
                'roles' => $roles,
                'site_ids' => $siteIds,
            ]);

            if (array_key_exists('password', $data) || ! $isActive || array_key_exists('roles', $data) || array_key_exists('site_ids', $data)) {
                $this->revokeUserSessions($user, $request, $actor);
            }

            return true;
        });

        if (! $canSave) {
            return $this->conflict('Sistem harus memiliki minimal satu SUPER_ADMIN aktif.');
        }

        return $this->success($this->userResource($user->refresh()->load(['roles:id,name', 'sites:id,name,code'])), 'Pengguna berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'users.manage');
        $this->assertUserScope($request, $user);

        if ($user->is($request->user())) {
            return $this->conflict('Anda tidak dapat menghapus akun sendiri.');
        }
        $canDelete = DB::transaction(function () use ($request, $user): bool {
            $this->lockUserAdministration();
            $user->refresh();
            if ($this->wouldRemoveLastActiveSuperAdmin($user, [], false)) {
                return false;
            }

            $this->revokeUserSessions($user);
            $user->delete();
            $this->audit($request, $user, 'user.deleted');

            return true;
        });

        if (! $canDelete) {
            return $this->conflict('Sistem harus memiliki minimal satu SUPER_ADMIN aktif.');
        }

        return $this->success(null, 'Pengguna berhasil dihapus.');
    }

    private function visibleUsersQuery(Request $request): Builder
    {
        $actor = $request->user();
        $query = User::query();

        if ($actor->hasRole('SUPER_ADMIN')) {
            return $query;
        }

        $siteIds = $actor->sites()->pluck('sites.id');

        return $query->where(function (Builder $users) use ($actor, $siteIds): void {
            $users->whereKey($actor->getKey());
            if ($siteIds->isNotEmpty()) {
                $users->orWhere(function (Builder $scoped) use ($siteIds): void {
                    $scoped->whereHas('sites', fn (Builder $sites) => $sites->whereIn('sites.id', $siteIds))
                        ->whereDoesntHave('sites', fn (Builder $sites) => $sites->whereNotIn('sites.id', $siteIds));
                });
            }
        })->where(function (Builder $users) use ($actor): void {
            $users->whereKey($actor->getKey())
                ->orWhereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', ['SUPER_ADMIN', 'ADMIN']));
        });
    }

    private function assertUserScope(Request $request, User $user): void
    {
        abort_unless($this->visibleUsersQuery($request)->whereKey($user->getKey())->exists(), 404, 'Pengguna tidak ditemukan.');
    }

    private function assertAssignableRoles(Request $request, array $roles): void
    {
        if (! $request->user()->hasRole('SUPER_ADMIN') && array_intersect($roles, ['SUPER_ADMIN', 'ADMIN']) !== []) {
            abort(403, 'Hanya SUPER_ADMIN yang dapat menetapkan role administratif.');
        }
    }

    private function assertAssignedSites(Request $request, array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $sites = Site::query()->whereIn('id', $siteIds)->get(['id', 'is_active']);
        if ($sites->count() !== count($siteIds)) {
            abort(404, 'Lokasi tidak ditemukan.');
        }
        foreach ($siteIds as $siteId) {
            $this->assertSiteScope($request, $siteId);
        }
        if ($sites->contains(fn (Site $site): bool => ! $site->is_active)) {
            abort(422, 'Pengguna hanya dapat dihubungkan ke lokasi aktif.');
        }
    }

    private function requireSiteScopeForRoles(array $roles, array $siteIds): void
    {
        if (! in_array('SUPER_ADMIN', $roles, true) && $siteIds === []) {
            abort(422, 'Pengguna non-SUPER_ADMIN wajib memiliki minimal satu lokasi.');
        }
    }

    private function wouldRemoveLastActiveSuperAdmin(User $target, array $nextRoles, bool $nextActive): bool
    {
        if (! $target->hasRole('SUPER_ADMIN') || (in_array('SUPER_ADMIN', $nextRoles, true) && $nextActive)) {
            return false;
        }

        return User::role('SUPER_ADMIN', 'web')->where('is_active', true)->count() <= 1;
    }

    private function lockUserAdministration(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(824731905)');
    }

    private function revokeUserSessions(User $user, ?Request $request = null, ?User $actor = null): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        if ($request && $actor?->is($user) && $request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'two_factor_enabled' => $user->totp_confirmed_at !== null,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'sites' => $user->sites->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
            ])->values(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
