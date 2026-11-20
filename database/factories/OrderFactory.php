<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['public_reference' => (string) Str::ulid(), 'purchaser_user_id' => null, 'holder_user_id' => null, 'membership_plan_id' => MembershipPlan::factory(),
            'purchaser_first_name' => 'Kupujący', 'purchaser_last_name' => 'Testowy', 'purchaser_email' => 'buyer@example.test', 'purchaser_phone' => '+48600111222',
            'holder_first_name' => 'Posiadacz', 'holder_last_name' => 'Testowy', 'holder_email' => 'holder@example.test', 'holder_phone' => '+48600111222', 'recipient_email' => 'recipient@example.test',
            'plan_snapshot' => function (array $attributes): array {
                $plan = MembershipPlan::findOrFail((int) $attributes['membership_plan_id']);

                return ['snapshot_version' => 1, 'name' => $plan->name, 'kind' => $plan->kind->value, 'price_grosz' => $plan->price_grosz, 'currency' => $plan->currency,
                    'duration_months' => $plan->duration_months, 'included_entries' => $plan->included_entries, 'terms' => $plan->terms];
            },
            'amount_grosz' => fn (array $attributes): int => MembershipPlan::findOrFail((int) $attributes['membership_plan_id'])->price_grosz,
            'currency' => 'PLN', 'sales_channel' => SalesChannel::Online, 'payment_method' => PaymentMethod::Przelewy24, 'status' => OrderStatus::Pending,
            'ordered_at' => now(), 'paid_at' => null, 'created_by_user_id' => null, 'accepted_regulation_version_id' => null, 'regulation_accepted_at' => null];
    }

    public function paidAtReception(): static
    {
        return $this->state(fn (): array => ['sales_channel' => SalesChannel::Reception, 'payment_method' => PaymentMethod::Cash, 'status' => OrderStatus::Paid, 'paid_at' => now(), 'created_by_user_id' => User::factory()->employee()]);
    }
}
