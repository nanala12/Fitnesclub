<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\s().-]+/u', '', trim($value)) ?? $value;

        return str_starts_with($value, '00') ? '+'.substr($value, 2) : $value;
    }
}
