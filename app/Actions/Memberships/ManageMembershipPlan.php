<?php

namespace App\Actions\Memberships;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\MembershipPlan;
use App\Models\PriceListCategory;
use App\Models\User;
use App\Support\PlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageMembershipPlan
{
    public function __construct(private RecordAudit $audit) {}

    /** @param array{category?:string,name:string,description:?string,kind:string,price:string,duration_months:?int,included_entries:?int,is_active:bool,is_public:bool,is_featured:bool,sort_order:int,revision:int} $data */
    public function save(User $actor, ?int $planId, array $data): MembershipPlan
    {
        Gate::forUser($actor)->authorize('manage', MembershipPlan::class);

        return DB::transaction(function () use ($actor, $planId, $data): MembershipPlan {
            $this->lockCatalog();
            $plan = $planId === null ? new MembershipPlan : MembershipPlan::query()->lockForUpdate()->findOrFail($planId);
            if ($planId !== null) {
                $this->checkRevision($plan, $data['revision']);
            }
            if ($data['is_featured']) {
                // A locking read observes current rows even inside an outer REPEATABLE READ transaction.
                $featured = MembershipPlan::query()->where('is_featured', true)->when($planId !== null, fn ($query) => $query->whereKeyNot($planId))->lockForUpdate()->get(['id'])->all();
                if (count($featured) >= 3) {
                    throw ValidationException::withMessages(['is_featured' => 'Możesz wyróżnić najwyżej trzy plany. Najpierw wyłącz wyróżnienie innego planu.']);
                }
            }
            $price = PlanPrice::parse($data['price']);
            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Wpisz poprawną cenę w złotych.']);
            }
            $before = $planId === null ? null : $this->summary($plan);
            $categoryKey = $data['category'] ?? $plan->category ?? 'gym';
            $category = PriceListCategory::query()->where('key', $categoryKey)->lockForUpdate()->first();
            if ($category === null) {
                throw ValidationException::withMessages(['category' => 'Wybierz istniejącą kategorię cennika.']);
            }
            $attributes = ['category' => $category->key,
                'name' => $data['name'], 'description' => $data['description'], 'kind' => $data['kind'],
                'price_grosz' => $price, 'currency' => 'PLN', 'duration_months' => $data['kind'] === 'time' ? $data['duration_months'] : null,
                'included_entries' => $data['included_entries'], 'is_active' => $data['is_active'], 'is_public' => $data['is_public'],
                'is_featured' => $data['is_featured'], 'sort_order' => $data['sort_order'],
            ];
            $plan->forceFill($attributes);
            $changed = array_keys($plan->getDirty());
            $plan->forceFill(['revision' => $planId === null ? 0 : $plan->revision + 1])->save();
            $this->audit->handle($planId === null ? AuditAction::PlanCreated : AuditAction::PlanUpdated, $plan->id, $actor, $before, $this->summary($plan), ['changed_fields' => $changed]);

            return $plan;
        }, 3);
    }

    public function visibility(User $actor, int $planId, int $revision, bool $visible): void
    {
        Gate::forUser($actor)->authorize('manage', MembershipPlan::class);
        DB::transaction(function () use ($actor, $planId, $revision, $visible): void {
            $this->lockCatalog();
            $plan = MembershipPlan::query()->lockForUpdate()->findOrFail($planId);
            $this->checkRevision($plan, $revision);
            $before = $this->summary($plan);
            $plan->forceFill(['is_public' => $visible, 'revision' => $plan->revision + 1])->save();
            $this->audit->handle(AuditAction::PlanUpdated, $plan->id, $actor, $before, $this->summary($plan), ['changed_fields' => ['is_public']]);
        }, 3);
    }

    public function delete(User $actor, int $planId, int $revision): void
    {
        Gate::forUser($actor)->authorize('manage', MembershipPlan::class);
        DB::transaction(function () use ($actor, $planId, $revision): void {
            $this->lockCatalog();
            $plan = MembershipPlan::query()->lockForUpdate()->findOrFail($planId);
            $this->checkRevision($plan, $revision);
            if ($plan->orders()->exists() || $plan->memberships()->exists()) {
                throw ValidationException::withMessages(['plan' => 'Ten plan ma historię zakupów. Wyłącz jego dostępność zamiast go usuwać.']);
            }
            $summary = $this->summary($plan);
            $plan->delete();
            $this->audit->handle(AuditAction::PlanDeleted, $planId, $actor, $summary, null);
        }, 3);
    }

    private function lockCatalog(): void
    {
        // A durable singleton also serializes concurrent inserts into an empty catalog.
        DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
    }

    private function checkRevision(MembershipPlan $plan, int $revision): void
    {
        if ($plan->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Ten plan został zmieniony. Wczytaj aktualne dane i ponów edycję.']);
        }
    }

    /** @return array<string, int|bool> */
    private function summary(MembershipPlan $plan): array
    {
        return ['price_grosz' => $plan->price_grosz, 'is_active' => $plan->is_active, 'is_public' => $plan->is_public, 'is_featured' => $plan->is_featured];
    }
}
