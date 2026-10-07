<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceNetworkViolation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'device_id', 'site_id', 'reason', 'observed_value', 'status', 'occurrences',
        'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
