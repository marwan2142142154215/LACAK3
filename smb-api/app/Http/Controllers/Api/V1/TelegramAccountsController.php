<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TelegramAccountsController extends ApiController
{
    public function eligibleUsers(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'telegram.manage');
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:160']]);
        $query = $this->eligibleUsersQuery($request);
        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $users) => $users
                ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                ->orWhereRaw('LOWER(username) LIKE LOWER(?)', [$term])
                ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]));
        }
        $paginator = $query->orderBy('name')->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
        ]));

        return $this->paginated($paginator, 'Daftar pengguna yang dapat dipetakan berhasil diambil.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'telegram.manage');
        $query = TelegramAccount::query()->whereIn('user_id', $this->visibleUsersQuery($request)->select('users.id'))
            ->with(['user:id,name,username,email', 'approver:id,name']);
        if ($request->has('is_active')) {
            $request->validate(['is_active' => ['required', 'boolean']]);
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->paginated($query->orderByDesc('created_at')->paginate($this->perPage($request)), 'Akun Telegram berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'telegram.manage');
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'telegram_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
        ]);

        $account = DB::transaction(function () use ($request, $data): TelegramAccount {
            $this->lockUserAdministration();
            $target = $this->eligibleUsersQuery($request)->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            $existing = TelegramAccount::withTrashed()->where('telegram_id', (string) $data['telegram_id'])->lockForUpdate()->first();
            if ($existing && ! $existing->trashed()) {
                throw ValidationException::withMessages(['telegram_id' => 'Telegram ID sudah dipetakan.']);
            }

            if ($existing) {
                $existing->restore();
                $existing->update([
                    'user_id' => $target->id,
                    'is_active' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                    'last_seen_at' => null,
                ]);
                $this->audit($request, $existing, 'telegram.account.restored', [
                    'user_id' => $target->id,
                    'telegram_id' => $existing->telegram_id,
                    'is_active' => false,
                ]);

                return $existing;
            }

            $account = TelegramAccount::create([
                'user_id' => $target->id,
                'telegram_id' => (string) $data['telegram_id'],
                'is_active' => false,
            ]);
            $this->audit($request, $account, 'telegram.account.created', [
                'user_id' => $account->user_id,
                'telegram_id' => $account->telegram_id,
                'is_active' => false,
            ]);

            return $account;
        });

        return $this->success($account->load('user:id,name,username,email'), $account->wasRecentlyCreated
            ? 'Akun Telegram dibuat dalam status menunggu persetujuan.'
            : 'Pemetaan Telegram diaktifkan kembali dalam status menunggu persetujuan.', 201);
    }

    public function update(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizePermission($request, 'telegram.manage');
        $this->assertAccountScope($request, $telegramAccount);
        $data = $request->validate([
            'user_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        abort_if($data === [], 422, 'Pilih perubahan yang akan disimpan.');

        DB::transaction(function () use ($request, $telegramAccount, $data): void {
            $this->lockUserAdministration();
            $account = TelegramAccount::query()->whereKey($telegramAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertAccountScope($request, $account);
            $changes = [];
            if (array_key_exists('user_id', $data)) {
                $target = $this->eligibleUsersQuery($request)->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
                $changes['user_id'] = $target->id;
            }
            if (array_key_exists('is_active', $data)) {
                $changes['is_active'] = $data['is_active'];
                if ($data['is_active']) {
                    $changes['approved_by'] = $request->user()->id;
                    $changes['approved_at'] = now();
                }
            }
            $account->update($changes);
            $this->audit($request, $account, 'telegram.account.updated', [
                'changed_fields' => array_keys($changes),
                'user_id' => $account->user_id,
                'telegram_id' => $account->telegram_id,
                'is_active' => $account->is_active,
            ]);
        });

        return $this->success($telegramAccount->refresh()->load(['user:id,name,username,email', 'approver:id,name']), 'Akun Telegram berhasil diperbarui.');
    }

    public function destroy(Request $request, TelegramAccount $telegramAccount): JsonResponse
    {
        $this->authorizePermission($request, 'telegram.manage');
        $this->assertAccountScope($request, $telegramAccount);

        DB::transaction(function () use ($request, $telegramAccount): void {
            $this->lockUserAdministration();
            $account = TelegramAccount::query()->whereKey($telegramAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertAccountScope($request, $account);
            $account->delete();
            $this->audit($request, $account, 'telegram.account.deleted', [
                'telegram_id' => $account->telegram_id,
                'user_id' => $account->user_id,
                'was_active' => $account->is_active,
            ]);
        });

        return $this->success(null, 'Akun Telegram dicabut.');
    }

    private function eligibleUsersQuery(Request $request): Builder
    {
        return $this->visibleUsersQuery($request)->where('users.is_active', true);
    }

    private function visibleUsersQuery(Request $request): Builder
    {
        $actor = $request->user();
        $query = User::query();
        if ($actor->hasRole('SUPER_ADMIN')) {
            return $query;
        }

        $siteIds = $actor->sites()->pluck('sites.id');
        $query->where(function (Builder $users) use ($actor, $siteIds): void {
            $users->whereKey($actor->getKey());
            if ($siteIds->isNotEmpty()) {
                $users->orWhere(function (Builder $scoped) use ($siteIds): void {
                    $scoped->whereHas('sites', fn (Builder $sites) => $sites->whereIn('sites.id', $siteIds))
                        ->whereDoesntHave('sites', fn (Builder $sites) => $sites->whereNotIn('sites.id', $siteIds))
                        ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', ['SUPER_ADMIN', 'ADMIN']));
                });
            }
        });

        return $query;
    }

    private function assertAccountScope(Request $request, TelegramAccount $account): void
    {
        abort_unless($this->visibleUsersQuery($request)->whereKey($account->user_id)->exists(), 404, 'Akun Telegram tidak ditemukan.');
    }

    private function lockUserAdministration(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(824731905)');
    }
}
