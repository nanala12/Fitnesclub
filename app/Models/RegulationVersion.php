<?php

namespace App\Models;

use App\Database\CmsContentBuilder;
use App\Models\Concerns\HasOwnedMedia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $published_at */
#[UseEloquentBuilder(CmsContentBuilder::class)]
class RegulationVersion extends Model
{
    use HasOwnedMedia;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
