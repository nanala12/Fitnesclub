<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $published_at */
class NewsPost extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }
}
