<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuthChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'token_hash', 'client_type', 'device_name', 'totp_setup_secret', 'attempts', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'totp_setup_secret' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
