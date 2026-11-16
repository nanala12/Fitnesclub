<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'size_bytes' => 'integer'];
    }
}
