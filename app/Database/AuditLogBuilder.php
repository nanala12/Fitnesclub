<?php

namespace App\Database;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<AuditLog> */
class AuditLogBuilder extends Builder
{
    public function update(array $values): never
    {
        throw new LogicException('Audit entries cannot be changed.');
    }

    public function delete(): never
    {
        throw new LogicException('Audit entries cannot be deleted.');
    }

    public function forceDelete(): never
    {
        throw new LogicException('Audit entries cannot be deleted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException('Audit entries cannot be overwritten.');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Audit entries cannot be changed.');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw new LogicException('Audit entries cannot be changed.');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw new LogicException('Audit entries cannot be changed.');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw new LogicException('Audit entries cannot be changed.');
    }

    public function truncate(): never
    {
        throw new LogicException('Audit entries cannot be deleted.');
    }
}
