<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningHour extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'weekday' => 'integer'];
    }
}
