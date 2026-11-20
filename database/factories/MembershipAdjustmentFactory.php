<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MembershipAdjustment> */
class MembershipAdjustmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['membership_id' => Membership::factory(), 'source_membership_id' => null, 'kind' => 'rollover', 'delta_entries' => 3, 'performed_by_user_id' => null,
            'reason' => null, 'idempotency_key' => 'test-rollover-'.Str::uuid(), 'created_at' => now()];
    }
}
