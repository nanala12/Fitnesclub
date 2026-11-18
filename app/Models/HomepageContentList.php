<?php

namespace App\Models;

use App\Enums\HomepageSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class HomepageContentList extends Model
{
    protected $primaryKey = 'section';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public static function lock(HomepageSection $section): self
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Homepage list locks require a content transaction.');
        }

        return self::query()->whereKey($section->value)->lockForUpdate()->firstOrFail();
    }

    public function checkRevision(int $revision): void
    {
        if ($this->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Zawartość tej sekcji została już zmieniona. Wczytaj aktualne dane i ponów edycję.']);
        }
    }

    public function advance(): void
    {
        $this->forceFill(['revision' => $this->revision + 1])->save();
    }
}
