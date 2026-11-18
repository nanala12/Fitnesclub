<?php

namespace App\Enums;

use App\Models\HeroSlide;
use App\Models\OfferTile;

enum HomepageSection: string
{
    case Tiles = 'kafelki';
    case Slides = 'karuzela';

    /** @return class-string<OfferTile>|class-string<HeroSlide> */
    public function model(): string
    {
        return $this === self::Tiles ? OfferTile::class : HeroSlide::class;
    }

    public function audit(string $operation): AuditAction
    {
        return match ([$this, $operation]) {
            [self::Tiles, 'created'] => AuditAction::TileCreated,
            [self::Tiles, 'updated'] => AuditAction::TileUpdated,
            [self::Tiles, 'deleted'] => AuditAction::TileDeleted,
            [self::Tiles, 'reordered'] => AuditAction::TileReordered,
            [self::Slides, 'created'] => AuditAction::SlideCreated,
            [self::Slides, 'updated'] => AuditAction::SlideUpdated,
            [self::Slides, 'deleted'] => AuditAction::SlideDeleted,
            [self::Slides, 'reordered'] => AuditAction::SlideReordered,
            default => throw new \LogicException('Unsupported homepage audit operation.'),
        };
    }

    public static function forPlacement(MediaPlacement $placement): ?self
    {
        return match ($placement) {
            MediaPlacement::Offer => self::Tiles,
            MediaPlacement::Slide => self::Slides,
            default => null,
        };
    }
}
