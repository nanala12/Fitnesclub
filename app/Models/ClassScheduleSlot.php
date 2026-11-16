<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassScheduleSlot extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'weekday' => 'integer'];
    }

    /** @return BelongsTo<ClassType, $this> */
    public function classType(): BelongsTo
    {
        return $this->belongsTo(ClassType::class, 'class_type_id');
    }
}
