<?php

namespace Database\Factories;

use App\Enums\PlanKind;
use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipPlan> */
class MembershipPlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Plan testowy', 'description' => 'Testowe warunki planu', 'kind' => PlanKind::Time, 'price_grosz' => 15000, 'currency' => 'PLN', 'duration_months' => 1,
            'included_entries' => null, 'terms' => [], 'is_active' => false, 'is_public' => false, 'is_featured' => false, 'sort_order' => 0];
    }

    public function entries(int $count = 8): static
    {
        return $this->state(fn (): array => ['kind' => PlanKind::Entries, 'duration_months' => null, 'included_entries' => $count]);
    }
}
