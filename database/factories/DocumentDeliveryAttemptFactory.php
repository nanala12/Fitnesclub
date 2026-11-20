<?php

namespace Database\Factories;

use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentDeliveryAttempt> */
class DocumentDeliveryAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['document_delivery_id' => DocumentDelivery::factory(), 'attempt_number' => 1, 'status' => 'sent', 'error_code' => null, 'triggered_by_user_id' => null, 'attempted_at' => now()];
    }
}
