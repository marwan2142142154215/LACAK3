<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReleaseArtifact extends Model
{
    use HasUuids;

    protected $table = 'device_release_artifacts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['download_path'];

    protected $fillable = [
        'app_name', 'version', 'version_code', 'minimum_android_api', 'released_at',
        'download_path', 'checksum_sha256', 'is_active',
        'signing_certificate_sha256', 'signature_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'minimum_android_api' => 'integer',
            'released_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'signature_verified_at' => 'immutable_datetime',
        ];
    }
}
