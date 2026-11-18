<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GalleryCategory extends Model
{
    /** @return HasMany<GalleryImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }
}
