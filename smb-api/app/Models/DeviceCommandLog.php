<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommandLog extends Model
{
    protected $fillable = [
        'command_id', 'device_id', 'actor_user_id', 'correlation_id', 'event', 'actor_type', 'details',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'command_id');
    }
}
