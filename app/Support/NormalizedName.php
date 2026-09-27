<?php

namespace App\Support;

use Illuminate\Support\Str;

class NormalizedName
{
    public static function from(string $value): string
    {
        return Str::squish(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', Str::lower($value)) ?? '');
    }
}
