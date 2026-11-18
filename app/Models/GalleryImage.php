<?php

namespace App\Models;

use App\Database\CmsContentBuilder;
use App\Models\Concerns\HasOwnedMedia;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseEloquentBuilder(CmsContentBuilder::class)]
class GalleryImage extends Model
{
    use HasOwnedMedia;

    /** @var array<string, mixed> */
    protected $attributes = ['is_published' => false];

    /** @return BelongsTo<GalleryCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(GalleryCategory::class, 'gallery_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_published' => 'boolean'];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
