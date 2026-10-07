<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceBleObservation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'observation_id', 'device_id', 'observed_by_user_id', 'rssi', 'proximity', 'observed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rssi' => 'integer',
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by_user_id');
    }
}
