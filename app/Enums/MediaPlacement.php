<?php

namespace App\Enums;

use App\Models\GalleryImage;
use App\Models\HeroSlide;
use App\Models\NewsPost;
use App\Models\OfferTile;
use App\Models\RegulationVersion;
use Illuminate\Database\Eloquent\Model;

enum MediaPlacement: string
{
    case Offer = 'offer';
    case Slide = 'slide';
    case News = 'news';
    case Gallery = 'gallery';
    case Regulation = 'regulation';

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Offer => OfferTile::class,
            self::Slide => HeroSlide::class,
            self::News => NewsPost::class,
            self::Gallery => GalleryImage::class,
            self::Regulation => RegulationVersion::class,
        };
    }

    public function column(): string
    {
        return $this === self::News ? 'cover_media_id' : 'media_asset_id';
    }

    public function requiresImage(): bool
    {
        return in_array($this, [self::Slide, self::Gallery], true);
    }

    public static function forModel(Model $model): self
    {
        foreach (self::cases() as $placement) {
            if ($model::class === $placement->model()) {
                return $placement;
            }
        }
        throw new \LogicException('Unsupported CMS media owner.');
    }
}
