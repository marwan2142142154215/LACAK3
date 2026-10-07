<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $appends = ['status'];

    protected $hidden = ['ble_ephemeral_id_hash'];

    protected $fillable = [
        'name', 'manufacturer', 'model', 'site_id', 'team_id',
        'android_version', 'android_api', 'app_version', 'app_version_code',
        'battery_percent', 'network_type', 'connection_state', 'lock_state',
        'last_seen_at', 'last_location_at', 'capabilities', 'managed_device', 'device_owner',
        'credential_epoch',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_seen_at' => 'immutable_datetime',
            'last_location_at' => 'immutable_datetime',
            'ble_identity_expires_at' => 'immutable_datetime',
            'battery_percent' => 'integer',
            'android_api' => 'integer',
            'app_version_code' => 'integer',
            'credential_epoch' => 'integer',
            'managed_device' => 'boolean',
            'device_owner' => 'boolean',
        ];
    }

    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->last_seen_at === null) {
                return 'UNKNOWN';
            }

            $age = now()->diffInSeconds($this->last_seen_at);

            if ($age <= (int) config('smb.device_online_after_seconds', 90)
                && $this->connection_state === 'CONNECTED') {
                return 'ONLINE';
            }

            if ($age <= (int) config('smb.device_degraded_after_seconds', 600)) {
                return 'DEGRADED';
            }

            return 'OFFLINE';
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(DeviceLocation::class)->latestOfMany('recorded_at');
    }
}
