<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'timezone', 'is_active', 'allowed_wifi_ssids', 'allowed_ip_ranges'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allowed_wifi_ssids' => 'array',
            'allowed_ip_ranges' => 'array',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
