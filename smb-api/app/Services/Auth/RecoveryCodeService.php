<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

class RecoveryCodeService
{
    /** @return list<string> */
    public function generate(int $count = 10): array
    {
        return collect(range(1, $count))->map(function (): string {
            $value = Str::upper(Str::random(24));

            return implode('-', str_split($value, 6));
        })->all();
    }

    public function hash(string $code): string
    {
        return hash('sha256', Str::upper(preg_replace('/[^a-z0-9]/i', '', $code) ?? ''));
    }
}
