<?php

namespace App\Models;

use App\Casts\IntegerGrosz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $price_grosz
 * @property string $category
 * @property string $name
 * @property bool $is_published
 * @property int $sort_order
 * @property int $revision
 */
class PriceListService extends Model
{
    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['price_grosz' => IntegerGrosz::class, 'is_published' => 'boolean', 'sort_order' => 'integer', 'revision' => 'integer'];
    }

    /** @return BelongsTo<PriceListCategory,$this> */
    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceListCategory::class, 'category', 'key');
    }
}
