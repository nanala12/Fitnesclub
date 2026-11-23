<?php

namespace App\Support;

class PlanPrice
{
    public static function parse(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^([0-9]{1,17})(?:[.,]([0-9]{1,2}))?$/D', $value, $parts)) {
            return null;
        }
        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0') ?: '0';
        $amount = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $amount === false ? null : $amount;
    }

    public static function decimal(int $grosz): string
    {
        return intdiv($grosz, 100).','.str_pad((string) ($grosz % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function label(int $grosz): string
    {
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', (string) intdiv($grosz, 100));

        return $whole.','.str_pad((string) ($grosz % 100), 2, '0', STR_PAD_LEFT).' zł';
    }
}
