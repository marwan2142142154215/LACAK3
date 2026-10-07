<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCommand extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'device_id', 'sequence', 'command_type', 'idempotency_key', 'payload', 'status',
        'created_by', 'expires_at', 'sent_at', 'delivered_at', 'received_at', 'executing_at',
        'completed_at', 'result_code', 'result_message',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'executing_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeviceCommandLog::class, 'command_id');
    }
}
