<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MembershipEntry> */
class MembershipEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['membership_id' => Membership::factory()->state(['starts_on' => '2026-01-01', 'expires_on' => '2026-01-31', 'activated_at' => '2026-01-01 08:00:00', 'activated_by_user_id' => User::factory()->employee()]),
            'recorded_by_user_id' => User::factory()->employee(), 'used_at' => '2026-01-02 08:00:00', 'request_id' => (string) Str::uuid(), 'created_at' => now()];
    }
}
