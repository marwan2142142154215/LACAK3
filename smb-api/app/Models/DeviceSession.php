<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'device_id', 'session_generation', 'connection_id', 'state', 'authenticated_at',
        'transport', 'connected_at', 'last_heartbeat_at', 'disconnected_at', 'expires_at', 'observed_ip',
    ];

    protected function casts(): array
    {
        return [
            'authenticated_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
