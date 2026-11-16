<?php

namespace App\Database;

use App\Enums\MediaPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class CmsContentBuilder extends Builder
{
    public function update(array $values)
    {
        if (array_key_exists(MediaPlacement::forModel($this->getModel())->column(), $values)) {
            $this->requireOwnedWrite();
        }

        return parent::update($values);
    }

    public function delete()
    {
        $this->requireOwnedWrite();

        return parent::delete();
    }

    public function forceDelete()
    {
        $this->requireOwnedWrite();

        return parent::forceDelete();
    }

    /** @param array<mixed> $values */
    public function insert(array $values): bool
    {
        $this->requireOwnedWrite();

        return $this->toBase()->insert($values);
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values, ?string $sequence = null): int
    {
        $this->requireOwnedWrite();

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException('CMS media writes require individual model saves.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw new LogicException('CMS media writes require individual model saves.');
    }

    public function truncate(): never
    {
        throw new LogicException('CMS deletion requires individual model deletes.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->checkMediaColumns($extra);
        if (! is_string($column) || $column === MediaPlacement::forModel($this->getModel())->column()) {
            $this->requireOwnedWrite();
        }

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->checkMediaColumns($extra);
        if (! is_string($column) || $column === MediaPlacement::forModel($this->getModel())->column()) {
            $this->requireOwnedWrite();
        }

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->checkMediaColumns([...$columns, ...$extra]);

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->checkMediaColumns([...$columns, ...$extra]);

        return parent::decrementEach($columns, $extra);
    }

    /** @param array<mixed> $parameters */
    public function __call($method, $parameters)
    {
        if (in_array($method, ['insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing'], true)) {
            throw new LogicException('CMS media writes require individual model saves.');
        }

        return parent::__call($method, $parameters);
    }

    /** @param array<string, mixed> $values */
    private function checkMediaColumns(array $values): void
    {
        if (array_key_exists(MediaPlacement::forModel($this->getModel())->column(), $values)) {
            $this->requireOwnedWrite();
        }
    }

    private function requireOwnedWrite(): void
    {
        if (! method_exists($this->getModel(), 'isWritingOwnedMedia') || ! $this->getModel()->isWritingOwnedMedia()) {
            throw new LogicException('CMS media changes require individual model saves/deletes.');
        }
    }
}
