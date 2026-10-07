<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLocation extends Model
{
    protected $fillable = [
        'device_id', 'request_id', 'latitude', 'longitude', 'accuracy_meters', 'recorded_at',
        'received_at', 'source', 'battery_percent', 'network_type',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'recorded_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'battery_percent' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
