<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class GalleryContentState extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public static function lock(): self
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Gallery locks require a content transaction.');
        }

        return self::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public function checkRevision(int $revision): void
    {
        if ($revision !== $this->revision) {
            throw ValidationException::withMessages(['revision' => 'Galeria została zmieniona. Wczytaj aktualne dane i ponów edycję.']);
        }
    }

    public function advance(): void
    {
        $this->forceFill(['revision' => $this->revision + 1])->save();
    }
}
