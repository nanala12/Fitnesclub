<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $available_at */
class MediaFileDeletion extends Model
{
    protected $fillable = ['disk', 'path', 'available_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['available_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
