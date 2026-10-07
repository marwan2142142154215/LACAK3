<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use App\Models\DeviceRegistrationCode;
use App\Models\Site;
use App\Models\Team;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditLogsController extends ApiController
{
    private const SENSITIVE_KEY = '/password|otp|token|secret|credential|authorization|signature|private.?key|recovery.?code/i';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'audit.view');
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'site_id' => ['sometimes', 'integer', 'min:1'],
            'device_id' => ['sometimes', 'uuid'],
            'event' => ['sometimes', 'string', 'max:160'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $perPage = $this->perPage($request);

        if (isset($data['site_id'])) {
            $this->assertSiteScope($request, (int) $data['site_id']);
        }

        $query = Activity::query()->with('causer:id,name');
        $this->applyActorScope($query, $request);

        if (isset($data['site_id'])) {
            $this->applySiteFilter($query, (int) $data['site_id']);
        }

        if (isset($data['device_id'])) {
            $device = Device::query()->find($data['device_id']);
            abort_if($device === null, 404, 'Data tidak ditemukan.');
            $this->assertSiteScope($request, $device->site_id);
            $query->where('subject_type', Device::class)->where('subject_id', $device->id);
        }

        if (isset($data['event'])) {
            $query->where('description', $data['event']);
        }
        if (isset($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->where('created_at', '<=', $data['to']);
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(fn (Activity $activity): array => [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'event' => $activity->description,
            'subject_type' => $this->subjectType($activity->subject_type),
            'subject_id' => $activity->subject_id,
            'actor' => $activity->causer ? [
                'id' => $activity->causer->getKey(),
                'name' => $activity->causer->name,
            ] : null,
            'properties' => $this->redactProperties($activity->properties?->toArray() ?? []),
            'created_at' => $activity->created_at?->toISOString(),
        ]));

        return $this->paginated($paginator, 'Audit log berhasil diambil.');
    }

    private function applyActorScope(Builder $query, Request $request): void
    {
        $user = $request->user();
        if ($user->hasRole('SUPER_ADMIN')) {
            return;
        }

        $siteIds = $user->sites()->select('sites.id');
        $userIds = User::query()->select('users.id')->where(function (Builder $users) use ($user, $siteIds): void {
            $users->whereKey($user->getKey())
                ->orWhereIn('users.id', function ($access) use ($siteIds): void {
                    $access->select('user_site_access.user_id')->from('user_site_access')->whereIn('user_site_access.site_id', $siteIds);
                });
        });

        $query->where(function (Builder $scope) use ($user, $siteIds, $userIds): void {
            $scope->where(function (Builder $subject) use ($siteIds): void {
                $subject->where('subject_type', Device::class)
                    ->whereIn('subject_id', Device::query()->selectRaw('CAST(id AS varchar)')->whereIn('site_id', $siteIds));
            })->orWhere(function (Builder $subject) use ($siteIds): void {
                $subject->where('subject_type', Site::class)
                    ->whereIn('subject_id', Site::query()->selectRaw('CAST(sites.id AS varchar)')->whereIn('sites.id', $siteIds));
            })->orWhere(function (Builder $subject) use ($siteIds): void {
                $subject->where('subject_type', Team::class)
                    ->whereIn('subject_id', Team::query()->selectRaw('CAST(id AS varchar)')->whereIn('site_id', $siteIds));
            })->orWhere(function (Builder $subject) use ($siteIds): void {
                $subject->where('subject_type', DeviceRegistrationCode::class)
                    ->whereIn('subject_id', DeviceRegistrationCode::query()->selectRaw('CAST(id AS varchar)')->whereIn('site_id', $siteIds));
            })->orWhere(function (Builder $subject) use ($userIds): void {
                $subject->where('subject_type', User::class)
                    ->whereIn('subject_id', User::query()->selectRaw('CAST(users.id AS varchar)')->whereIn('users.id', $userIds));
            })->orWhere(function (Builder $subject) use ($userIds): void {
                $subject->where('subject_type', TelegramAccount::class)
                    ->whereIn('subject_id', TelegramAccount::query()->selectRaw('CAST(id AS varchar)')->whereIn('user_id', $userIds));
            })->orWhere(function (Builder $subject) use ($user): void {
                // Subjectless authentication/security events are visible only to their actor.
                $subject->whereNull('subject_type')->where('causer_type', User::class)
                    ->where('causer_id', (string) $user->getKey());
            });
        });
    }

    private function applySiteFilter(Builder $query, int $siteId): void
    {
        $deviceIds = Device::query()->selectRaw('CAST(id AS varchar)')->where('site_id', $siteId);
        $teamIds = Team::query()->selectRaw('CAST(id AS varchar)')->where('site_id', $siteId);
        $siteIds = Site::query()->selectRaw('CAST(sites.id AS varchar)')->where('sites.id', $siteId);

        $userIds = User::query()->select('users.id')->whereIn('users.id', function ($access) use ($siteId): void {
            $access->select('user_site_access.user_id')->from('user_site_access')->where('user_site_access.site_id', $siteId);
        });

        $query->where(function (Builder $scope) use ($siteId, $deviceIds, $teamIds, $userIds): void {
            $scope->where(function (Builder $subject) use ($siteIds): void {
                $subject->where('subject_type', Site::class)->whereIn('subject_id', $siteIds);
            })->orWhere(function (Builder $subject) use ($deviceIds): void {
                $subject->where('subject_type', Device::class)->whereIn('subject_id', $deviceIds);
            })->orWhere(function (Builder $subject) use ($teamIds): void {
                $subject->where('subject_type', Team::class)->whereIn('subject_id', $teamIds);
            })->orWhere(function (Builder $subject) use ($siteId): void {
                $subject->where('subject_type', DeviceRegistrationCode::class)
                    ->whereIn('subject_id', DeviceRegistrationCode::query()->selectRaw('CAST(id AS varchar)')->where('site_id', $siteId));
            })->orWhere(function (Builder $subject) use ($userIds): void {
                $subject->where('subject_type', User::class)
                    ->whereIn('subject_id', User::query()->selectRaw('CAST(users.id AS varchar)')->whereIn('users.id', $userIds));
            })->orWhere(function (Builder $subject) use ($userIds): void {
                $subject->where('subject_type', TelegramAccount::class)
                    ->whereIn('subject_id', TelegramAccount::query()->selectRaw('CAST(id AS varchar)')->whereIn('user_id', $userIds));
            });
        });
    }

    private function subjectType(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        return match ($class) {
            Device::class => 'device',
            Site::class => 'site',
            Team::class => 'team',
            DeviceRegistrationCode::class => 'registration_code',
            TelegramAccount::class => 'telegram_account',
            User::class => 'user',
            default => 'other',
        };
    }

    /** @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function redactProperties(array $properties): array
    {
        $safe = [];
        foreach ($properties as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, (string) $key) === 1) {
                $safe[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $safe[$key] = $this->redactProperties($value);
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
