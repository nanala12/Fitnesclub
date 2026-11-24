<?php

namespace App\Services\Documents;

use App\Exceptions\MembershipPdfException;
use App\Models\Membership;
use App\Models\Order;
use App\Support\PlanPrice;
use SensitiveParameter;

final readonly class MembershipPdfData
{
    private function __construct(
        public string $clubName, public string $holderName, public string $planName,
        public string $kind, public string $priceLabel, public ?int $durationMonths,
        public ?int $includedEntries, public ?int $grantedEntries,
        public ?string $startsOn, public ?string $expiresOn,
    ) {}

    public static function fromMembership(#[SensitiveParameter] Membership $membership, #[SensitiveParameter] Order $order, string $clubName): self
    {
        foreach (['membership_plan_id', 'holder_user_id', 'holder_first_name', 'holder_last_name', 'holder_email', 'holder_phone', 'plan_snapshot'] as $field) {
            if ($membership->getAttribute($field) !== $order->getAttribute($field)) {
                throw new MembershipPdfException('snapshot_mismatch');
            }
        }
        $snapshot = $membership->plan_snapshot;
        $kind = $snapshot['kind'] ?? null;
        $months = $snapshot['duration_months'] ?? null;
        $entries = $snapshot['included_entries'] ?? null;
        if (($snapshot['snapshot_version'] ?? null) !== 1 || ! is_string($snapshot['name'] ?? null) || trim($snapshot['name']) === ''
            || ($snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($snapshot['currency'] ?? null) !== $order->currency
            || ! in_array($kind, ['time', 'entries'], true)
            || ($kind === 'time' && (! is_int($months) || $months < 1 || $entries !== null || $membership->entries_granted !== null))
            || ($kind === 'entries' && ($months !== null || ! is_int($entries) || $entries < 1 || $membership->entries_granted === null || $membership->entries_granted < $entries))
            || ($membership->starts_on === null && $membership->expires_on !== null)
            || ($membership->starts_on !== null && $membership->activated_at === null && $membership->renewed_from_id === null)
            || ($kind === 'time' && $membership->starts_on !== null && $membership->expires_on === null)
            || ($kind === 'entries' && $membership->expires_on !== null)) {
            throw new MembershipPdfException('invalid_document_terms');
        }

        return new self($clubName, trim($membership->holder_first_name.' '.$membership->holder_last_name), $snapshot['name'], $kind,
            PlanPrice::label($order->amount_grosz), $months, $entries, $membership->entries_granted,
            $membership->starts_on?->format('d.m.Y'), $membership->expires_on?->format('d.m.Y'));
    }
}
