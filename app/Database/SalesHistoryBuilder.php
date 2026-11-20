<?php

namespace App\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @template TModel of Model
 * @extends Builder<TModel>
 */
class SalesHistoryBuilder extends Builder
{
    public function update(array $values)
    {
        $this->requireModelSave();

        return parent::update($values);
    }

    /** @param array<mixed> $values */
    public function insert(array $values): bool
    {
        $this->requireModelSave();

        return $this->toBase()->insert($values);
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values, ?string $sequence = null): int
    {
        $this->requireModelSave();

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function delete(): never
    {
        throw new LogicException('Sales history cannot be deleted.');
    }

    public function forceDelete(): never
    {
        throw new LogicException('Sales history cannot be deleted.');
    }

    public function truncate(): never
    {
        throw new LogicException('Sales history cannot be deleted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException('Sales history requires individual saves.');
    }

    /** @param array<string, mixed> $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw new LogicException('Sales history requires individual saves.');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Sales balances require locked model saves.');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Sales balances require locked model saves.');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw new LogicException('Sales balances require locked model saves.');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw new LogicException('Sales balances require locked model saves.');
    }

    /** @param array<mixed> $parameters */
    public function __call($method, $parameters)
    {
        if (in_array($method, ['insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing'], true)) {
            throw new LogicException('Sales history requires individual saves.');
        }

        return parent::__call($method, $parameters);
    }

    private function requireModelSave(): void
    {
        if (! method_exists($this->getModel(), 'isWritingSalesHistory') || ! $this->getModel()->isWritingSalesHistory()) {
            throw new LogicException('Sales history requires individual saves.');
        }
    }
}
