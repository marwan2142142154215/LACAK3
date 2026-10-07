<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TelegramAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'telegram_id', 'is_active', 'approved_by', 'approved_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'string',
            'is_active' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
