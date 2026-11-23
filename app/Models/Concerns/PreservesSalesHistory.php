<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use LogicException;

trait PreservesSalesHistory
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    private bool $writingSalesHistory = false;

    public function isWritingSalesHistory(): bool
    {
        return $this->writingSalesHistory;
    }

    /** @return list<string> */
    public function immutableHistoryFields(): array
    {
        return array_keys($this->getAttributes());
    }

    /** @return list<string> */
    public function historyFieldsSetOnce(): array
    {
        return [];
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if ($this->exists) {
                $persisted = $this->newQuery()->lockForUpdate()->whereKey($this->getRawOriginal($this->getKeyName()))->firstOrFail();
                $fields = ['id', 'created_at', ...$persisted->immutableHistoryFields(), ...array_filter($this->historyFieldsSetOnce(), fn (string $field): bool => $persisted->getAttribute($field) !== null)];
                foreach ($fields as $field) {
                    if ($this->isDirty($field) && ($this->getAttributes()[$field] ?? null) !== ($persisted->getAttributes()[$field] ?? null)) {
                        throw new LogicException('Historical purchase fields cannot be changed.');
                    }
                }
            }
            $this->writingSalesHistory = true;
            try {
                return parent::save($options);
            } finally {
                $this->writingSalesHistory = false;
            }
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return null;
        }
        throw new LogicException('Sales history cannot be deleted.');
    }
}
