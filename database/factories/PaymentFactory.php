<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'provider' => PaymentProvider::Przelewy24, 'method' => PaymentMethod::Przelewy24, 'status' => PaymentStatus::Initiated,
            'amount_grosz' => fn (array $attributes): int => Order::findOrFail((int) $attributes['order_id'])->amount_grosz, 'currency' => 'PLN', 'provider_session_id' => null,
            'provider_transaction_id' => null, 'confirmed_at' => null, 'confirmed_by_user_id' => null, 'failure_code' => null];
    }
}
