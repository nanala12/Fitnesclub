<?php

namespace App\Actions\Employees;

use App\Models\AuditLog;
use App\Models\User;

class RecordEmployeeAudit
{
    /** @param array<string, mixed>|null $before */
    public function handle(User $actor, User $employee, string $action, ?array $before): void
    {
        $changed = $before === null ? ['first_name', 'last_name', 'email', 'phone', 'role'] : array_keys($employee->getChanges());
        $changed = array_values(array_intersect($changed, ['first_name', 'last_name', 'email', 'phone', 'role', 'suspended_at']));
        $entry = new AuditLog;
        $entry->forceFill([
            'actor_user_id' => $actor->id, 'actor_type' => $actor->role->value,
            'action' => $action, 'subject_type' => 'user', 'subject_id' => $employee->id,
            'old_values' => $before === null ? null : ['role' => $before['role'], 'suspended' => $before['suspended_at'] !== null],
            'new_values' => ['role' => $employee->role->value, 'suspended' => $employee->isSuspended()],
            'metadata' => ['changed_fields' => $changed], 'occurred_at' => now(),
        ])->save();
    }
}
