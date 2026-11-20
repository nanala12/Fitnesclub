<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<int, int> */
class IntegerGrosz implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException('Money must fit a non-negative PHP integer.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Money must be supplied as integer grosze.');
        }

        return $value;
    }
}
