<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentDelivery> */
class DocumentDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['membership_id' => Membership::factory(), 'kind' => 'membership_pdf', 'recipient_email' => fn (array $attributes): string => Membership::findOrFail((int) $attributes['membership_id'])->order->recipient_email,
            'scheduled_for' => now(), 'status' => DeliveryStatus::Pending, 'sent_at' => null, 'storage_path' => null, 'idempotency_key' => 'test-delivery-'.Str::uuid()];
    }
}
