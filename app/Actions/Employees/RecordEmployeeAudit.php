<?php

namespace App\Actions\Employees;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\User;

class RecordEmployeeAudit
{
    public function __construct(private RecordAudit $audit) {}

    /** @param array<string, mixed>|null $before */
    public function handle(User $actor, User $employee, string $action, ?array $before): void
    {
        $changed = $before === null ? ['first_name', 'last_name', 'email', 'phone', 'role'] : array_keys($employee->getChanges());
        $changed = array_values(array_intersect($changed, ['first_name', 'last_name', 'email', 'phone', 'role', 'suspended_at']));
        $this->audit->handle(
            AuditAction::from($action), $employee->id, $actor,
            $before === null ? null : ['role' => $before['role'], 'suspended' => $before['suspended_at'] !== null],
            ['role' => $employee->role->value, 'suspended' => $employee->isSuspended()],
            ['changed_fields' => $changed],
        );
    }
}
