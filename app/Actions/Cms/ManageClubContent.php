<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\ClassScheduleSlot;
use App\Models\ClassType;
use App\Models\ClubContentState;
use App\Models\ClubSetting;
use App\Models\OpeningHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageClubContent
{
    public function __construct(private RecordAudit $audit) {}

    /** @param array<string, string|null> $settings
     * @param  list<array{weekday:int,status:string,opens_at?:string,closes_at?:string}>  $hours
     */
    public function saveContact(User $actor, int $revision, array $settings, array $hours): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $revision, $settings, $hours): void {
            $state = $this->lock($revision);
            $club = ClubSetting::query()->lockForUpdate()->find(1) ?? new ClubSetting;
            $club->forceFill(['id' => 1, ...$settings])->save();
            foreach ($hours as $day) {
                $opens = $day['status'] === 'open' ? ($day['opens_at'] ?? null) : null;
                $closes = $day['status'] === 'open' ? ($day['closes_at'] ?? null) : null;
                if ($day['status'] === 'open' && ($opens === null || $closes === null || $closes <= $opens)) {
                    throw ValidationException::withMessages(['hours' => 'Podaj prawidłowy przedział godzin otwarcia.']);
                }
                $row = OpeningHour::where('weekday', $day['weekday'])->lockForUpdate()->first() ?? new OpeningHour;
                $row->forceFill(['weekday' => $day['weekday'], 'is_closed' => $day['status'] === 'closed',
                    'opens_at' => $opens, 'closes_at' => $closes])->save();
            }
            $this->audit->handle(AuditAction::ClubContactUpdated, 1, $actor, null, null, ['changed_fields' => [...array_keys($settings), 'opening_hours']]);
            $state->advance();
        });
    }

    public function scheduleVisibility(User $actor, int $revision, bool $visible): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $revision, $visible): void {
            $state = $this->lock($revision);
            $club = ClubSetting::query()->lockForUpdate()->find(1) ?? new ClubSetting;
            $club->forceFill(['id' => 1, 'is_schedule_visible' => $visible])->save();
            $this->audit->handle(AuditAction::ClubScheduleVisibilityUpdated, 1, $actor, null, null, ['changed_fields' => ['is_schedule_visible']]);
            $state->advance();
        });
    }

    /** @param array{name:string,description:?string,is_published:bool} $data */
    public function saveType(User $actor, ?int $id, int $revision, array $data): ClassType
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $revision, $data): ClassType {
            $state = $this->lock($revision);
            $type = $id === null ? new ClassType : ClassType::query()->lockForUpdate()->findOrFail($id);
            $type->forceFill($data)->save();
            $this->audit->handle($id === null ? AuditAction::ClassTypeCreated : AuditAction::ClassTypeUpdated, $type->id, $actor, null, null, ['changed_fields' => ['name', 'description', 'is_published']]);
            $state->advance();

            return $type;
        });
    }

    /** @param array{class_type_id:int,weekday:int,starts_at:string,ends_at:string,instructor_name:?string,is_published:bool} $data */
    public function saveSlot(User $actor, ?int $id, int $revision, array $data): ClassScheduleSlot
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $revision, $data): ClassScheduleSlot {
            $state = $this->lock($revision);
            ClassType::query()->lockForUpdate()->findOrFail($data['class_type_id']);
            $slot = $id === null ? new ClassScheduleSlot : ClassScheduleSlot::query()->lockForUpdate()->findOrFail($id);
            $slot->forceFill($data)->save();
            $this->audit->handle($id === null ? AuditAction::ClassSlotCreated : AuditAction::ClassSlotUpdated, $slot->id, $actor, null, null, ['changed_fields' => ['class_type_id', 'weekday', 'starts_at', 'ends_at', 'instructor_name', 'is_published']]);
            $state->advance();

            return $slot;
        });
    }

    public function visibility(User $actor, bool $type, int $id, int $revision, bool $published): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $type, $id, $revision, $published): void {
            $state = $this->lock($revision);
            $model = $type ? ClassType::class : ClassScheduleSlot::class;
            $model::query()->lockForUpdate()->findOrFail($id)->forceFill(['is_published' => $published])->save();
            $this->audit->handle($type ? AuditAction::ClassTypeUpdated : AuditAction::ClassSlotUpdated, $id, $actor, null, null, ['changed_fields' => ['is_published']]);
            $state->advance();
        });
    }

    public function delete(User $actor, bool $type, int $id, int $revision): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $type, $id, $revision): void {
            $state = $this->lock($revision);
            $model = $type ? ClassType::class : ClassScheduleSlot::class;
            $item = $model::query()->lockForUpdate()->findOrFail($id);
            if ($type && ClassScheduleSlot::where('class_type_id', $id)->exists()) {
                throw ValidationException::withMessages(['class_type_id' => 'Najpierw przenieś lub usuń terminy tego rodzaju zajęć.']);
            }
            $item->delete();
            $this->audit->handle($type ? AuditAction::ClassTypeDeleted : AuditAction::ClassSlotDeleted, $id, $actor, null, null);
            $state->advance();
        });
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', ClubSetting::class);
    }

    private function lock(int $revision): ClubContentState
    {
        $state = ClubContentState::lock();
        $state->checkRevision($revision);

        return $state;
    }
}
