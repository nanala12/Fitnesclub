<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassType extends Model
{
    /** @return HasMany<ClassScheduleSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(ClassScheduleSlot::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
