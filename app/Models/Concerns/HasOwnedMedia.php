<?php

namespace App\Models\Concerns;

use App\Actions\Cms\RetireMediaAsset;
use App\Enums\MediaPlacement;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait HasOwnedMedia
{
    private bool $writingOwnedMedia = false;

    public function isWritingOwnedMedia(): bool
    {
        return $this->writingOwnedMedia;
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            $placement = MediaPlacement::forModel($this);
            $column = $placement->column();
            $persisted = $this->exists ? $this->newQuery()->lockForUpdate()->whereKey($this->getKey())->firstOrFail() : null;
            $previousId = $persisted?->getAttribute($column);
            // An unrelated save on a stale instance must not resurrect a retired image.
            if ($persisted !== null && ! $this->isDirty($column)) {
                $this->setAttribute($column, $previousId);
            }
            $nextId = $this->getAttribute($column);
            if ($previousId !== $nextId && $placement === MediaPlacement::Regulation && $persisted?->getAttribute('published_at') !== null) {
                throw ValidationException::withMessages(['image' => 'Nie można zmieniać zdjęcia opublikowanej wersji regulaminu. Utwórz nową wersję.']);
            }
            $assets = MediaAsset::query()->whereIn('id', array_filter([$previousId, $nextId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $next = $nextId === null ? null : $assets->get($nextId);
            if ($nextId !== null && ($next === null || ($next->owner_type !== null && ($next->owner_type !== $placement->value || $next->owner_id !== $this->getKey())))) {
                throw ValidationException::withMessages(['image' => 'To zdjęcie jest już używane w innym miejscu lub nie jest dostępne. Prześlij nowy plik.']);
            }
            $this->writingOwnedMedia = true;
            try {
                $saved = parent::save($options);
            } finally {
                $this->writingOwnedMedia = false;
            }
            if (! $saved) {
                return false;
            }
            if ($previousId !== null && $previousId !== $nextId && ($previous = $assets->get($previousId)) !== null) {
                app(RetireMediaAsset::class)->handle($previous);
            }
            if ($next !== null) {
                $next->forceFill(['owner_type' => $placement->value, 'owner_id' => $this->getKey()])->save();
            }

            return true;
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return null;
        }

        return DB::transaction(function (): ?bool {
            $placement = MediaPlacement::forModel($this);
            $persisted = $this->newQuery()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();
            $assetId = $persisted->getAttribute($placement->column());
            if ($assetId !== null && $placement === MediaPlacement::Regulation && $persisted->getAttribute('published_at') !== null) {
                throw ValidationException::withMessages(['image' => 'Nie można usunąć zdjęcia opublikowanej wersji regulaminu.']);
            }
            $asset = $assetId === null ? null : MediaAsset::query()->lockForUpdate()->whereKey($assetId)->firstOrFail();
            $this->writingOwnedMedia = true;
            try {
                $deleted = parent::delete();
            } finally {
                $this->writingOwnedMedia = false;
            }
            if ($deleted && $asset !== null) {
                app(RetireMediaAsset::class)->handle($asset);
            }

            return $deleted;
        });
    }
}
