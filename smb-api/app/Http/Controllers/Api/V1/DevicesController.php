<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCommandLog;
use App\Models\DeviceCredential;
use App\Models\DeviceSession;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DevicesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $request->validate([
            'site_id' => ['sometimes', 'integer', 'min:1'],
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'android_api' => ['sometimes', 'integer', 'min:26', 'max:36'],
            'battery_min' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'battery_max' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'last_seen_before' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['ONLINE', 'DEGRADED', 'OFFLINE', 'UNKNOWN'])],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);
        $query = Device::query()->with([
            'site:id,name,code',
            'team:id,site_id,name',
            'latestLocation:device_locations.id,device_locations.device_id,device_locations.latitude,device_locations.longitude,device_locations.accuracy_meters,device_locations.recorded_at,device_locations.source',
        ]);

        if (! $request->user()->hasRole('SUPER_ADMIN')) {
            $query->whereIn('site_id', $request->user()->sites()->select('sites.id'));
        }

        if ($request->filled('site_id')) {
            $siteId = (int) $request->query('site_id');
            $this->assertSiteScope($request, $siteId);
            $query->where('site_id', $siteId);
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->integer('team_id'));
        }
        if ($request->filled('android_api')) {
            $query->where('android_api', $request->integer('android_api'));
        }
        if ($request->filled('battery_max')) {
            $query->where('battery_percent', '<=', $request->integer('battery_max'));
        }
        if ($request->filled('battery_min')) {
            $query->where('battery_percent', '>=', $request->integer('battery_min'));
        }
        if ($request->filled('last_seen_before')) {
            $query->where('last_seen_at', '<=', $request->date('last_seen_before'));
        }
        if ($request->filled('search')) {
            $term = mb_strtolower(trim((string) $request->query('search')));
            $like = '%'.$term.'%';
            $query->where(function (Builder $builder) use ($term, $like): void {
                $builder->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(manufacturer, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', [$like]);
                if (preg_match('/\A[0-9a-f-]{36}\z/i', $term) === 1) {
                    $builder->orWhere('id', $term);
                }
            });
        }

        $this->applyStatusFilter($query, $request->query('status'));

        return $this->paginated($query->orderBy('name')->paginate($this->perPage($request)), 'Daftar perangkat berhasil diambil.');
    }

    public function show(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission($request, 'devices.view');
        $this->assertSiteScope($request, $device->site_id);

        return $this->success($device->load([
            'site:id,name,code',
            'team:id,site_id,name',
            'latestLocation:device_locations.id,device_locations.device_id,device_locations.latitude,device_locations.longitude,device_locations.accuracy_meters,device_locations.recorded_at,device_locations.source',
        ]), 'Detail perangkat berhasil diambil.');
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission($request, 'devices.update');
        $this->assertSiteScope($request, $device->site_id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:160'],
            'site_id' => ['sometimes', 'required', 'integer', 'exists:sites,id', 'required_with:team_id'],
            'team_id' => ['sometimes', 'required', 'integer', 'exists:teams,id', 'required_with:site_id'],
        ]);

        if (array_key_exists('site_id', $data) || array_key_exists('team_id', $data)) {
            $siteId = (int) ($data['site_id'] ?? $device->site_id);
            $teamId = (int) ($data['team_id'] ?? $device->team_id);
            $this->assertSiteScope($request, $siteId);
            abort_if(! Site::whereKey($siteId)->where('is_active', true)->exists(), 422, 'Lokasi tujuan tidak aktif.');
            abort_if(! Team::whereKey($teamId)->where('site_id', $siteId)->where('is_active', true)->exists(), 422, 'Tim tujuan tidak ditemukan atau tidak aktif pada lokasi tersebut.');
        }

        DB::transaction(function () use ($request, $device, $data): void {
            $device->update($data);
            $this->audit($request, $device, 'device.updated', ['changed_fields' => array_keys($data)]);
        });

        return $this->success($device->refresh()->load(['site:id,name,code', 'team:id,site_id,name']), 'Perangkat berhasil diperbarui.');
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission($request, 'devices.delete');
        $this->assertSiteScope($request, $device->site_id);

        DB::transaction(function () use ($request, $device): void {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $revokedCredentials = DeviceCredential::query()->where('device_id', $lockedDevice->id)
                ->whereNull('revoked_at')->update(['revoked_at' => $now, 'updated_at' => $now]);
            DeviceSession::query()->where('device_id', $lockedDevice->id)->where('state', 'AUTHENTICATED')
                ->update(['state' => 'DISCONNECTED', 'disconnected_at' => $now, 'expires_at' => $now, 'updated_at' => $now]);

            $cancellable = DeviceCommand::query()->where('device_id', $lockedDevice->id)
                ->whereNotIn('status', ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'])
                ->lockForUpdate()->get();
            foreach ($cancellable as $command) {
                $command->forceFill([
                    'status' => 'CANCELLED',
                    'expires_at' => $now,
                    'completed_at' => $now,
                    'result_code' => 'DEVICE_ARCHIVED',
                    'result_message' => 'Perangkat diarsipkan oleh administrator.',
                ])->save();
                DeviceCommandLog::query()->create([
                    'command_id' => $command->id,
                    'device_id' => $lockedDevice->id,
                    'actor_user_id' => $request->user()->id,
                    'correlation_id' => (string) Str::uuid(),
                    'event' => 'device.command.cancelled',
                    'actor_type' => 'USER',
                    'details' => ['status' => 'CANCELLED', 'reason' => 'DEVICE_ARCHIVED'],
                ]);
            }
            if ($cancellable->isNotEmpty()) {
                DB::table('transactional_outbox')->whereIn('aggregate_id', $cancellable->pluck('id'))->delete();
            }

            $lockedDevice->increment('credential_epoch');
            $lockedDevice->forceFill(['connection_state' => 'DISCONNECTED'])->save();
            $lockedDevice->delete();
            $this->audit($request, $lockedDevice, 'device.archived', [
                'revoked_credentials' => $revokedCredentials,
                'cancelled_commands' => $cancellable->count(),
            ]);
        });

        return $this->success(null, 'Perangkat berhasil diarsipkan.');
    }

    private function applyStatusFilter(Builder $query, mixed $status): void
    {
        if (! in_array($status, ['ONLINE', 'DEGRADED', 'OFFLINE', 'UNKNOWN'], true)) {
            return;
        }

        $now = now();
        $onlineCutoff = $now->copy()->subSeconds((int) config('smb.device_online_after_seconds', 90));
        $degradedCutoff = $now->copy()->subSeconds((int) config('smb.device_degraded_after_seconds', 600));

        match ($status) {
            'ONLINE' => $query->where('connection_state', 'CONNECTED')->where('last_seen_at', '>=', $onlineCutoff),
            'DEGRADED' => $query->where('last_seen_at', '>=', $degradedCutoff)->where(function (Builder $builder) use ($onlineCutoff): void {
                $builder->where('connection_state', '!=', 'CONNECTED')->orWhere('last_seen_at', '<', $onlineCutoff);
            }),
            'OFFLINE' => $query->whereNotNull('last_seen_at')->where('last_seen_at', '<', $degradedCutoff),
            'UNKNOWN' => $query->whereNull('last_seen_at'),
        };
    }
}
