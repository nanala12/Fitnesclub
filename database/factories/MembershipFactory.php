<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $data = ['order_id' => Order::factory()->paidAtReception(), 'renewed_from_id' => null, 'starts_on' => null, 'expires_on' => null, 'activated_at' => null, 'activated_by_user_id' => null];
        foreach (['membership_plan_id', 'holder_user_id', 'holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email', 'plan_snapshot'] as $field) {
            $data[$field] = fn (array $attributes) => Order::findOrFail((int) $attributes['order_id'])->getAttribute($field);
        }
        foreach (['entries_granted', 'entries_remaining'] as $field) {
            $data[$field] = fn (array $attributes) => Order::findOrFail((int) $attributes['order_id'])->plan_snapshot['included_entries'];
        }

        return $data;
    }
}
