<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditPayload;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordAudit
{
    public function __construct(private AuditPayload $payload) {}

    /**
     * Called only by trusted, already authorized business actions, inside their transaction.
     * A null actor explicitly means a system operation; never infer it from a missing web session.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function handle(AuditAction $action, int $subjectId, ?User $actor, ?array $before, ?array $after, array $metadata = []): AuditLog
    {
        if (DB::transactionLevel() < 1 || $subjectId < 1) {
            throw new LogicException('Audit writes require a business transaction and a persisted subject identifier.');
        }
        if ($actor !== null && (! $actor->exists || ! in_array($actor->role, [UserRole::Admin, UserRole::Employee], true))) {
            throw new LogicException('Audit actor must be a persisted staff account or explicitly system.');
        }

        $entry = new AuditLog;
        $entry->forceFill([
            'actor_user_id' => $actor?->id, 'actor_type' => $actor?->role->value ?? 'system',
            'action' => $action->value, 'subject_type' => $action->subjectType(), 'subject_id' => $subjectId,
            'old_values' => $this->payload->values($before), 'new_values' => $this->payload->values($after),
            'metadata' => $this->payload->metadata($metadata), 'occurred_at' => now(),
        ])->save();

        return $entry;
    }
}
