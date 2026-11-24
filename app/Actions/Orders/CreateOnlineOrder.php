<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PriceListCategory;
use App\Models\RegulationContentState;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateOnlineOrder
{
    /**
     * The account must come from the authenticated session, never from submitted contact data.
     * A null account explicitly selects guest checkout, without looking up matching users.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(?User $account, array $input): Order
    {
        $data = $this->validate($input);

        return DB::transaction(function () use ($account, $data): Order {
            // Share catalog/publication locks with CMS writers before taking individual row locks.
            DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
            $plan = MembershipPlan::query()->whereKey($data['membership_plan_id'])->lockForUpdate()->first();
            $category = $plan === null ? null : PriceListCategory::query()->where('key', $plan->category)->lockForUpdate()->first();
            if ($plan === null || ! $plan->is_active || ! $plan->is_public || ! ($category->is_published ?? false)) {
                throw ValidationException::withMessages(['membership_plan_id' => 'Ten karnet nie jest teraz dostępny. Wybierz inny karnet.']);
            }
            if ($plan->revision !== (int) $data['plan_revision']) {
                throw ValidationException::withMessages(['plan_revision' => 'Warunki karnetu zostały zmienione. Sprawdź aktualną ofertę i ponów zakup.']);
            }

            RegulationContentState::lock();
            $now = CarbonImmutable::now('UTC');
            $regulation = RegulationVersion::query()->whereNotNull('published_at')->where('published_at', '<=', $now)->orderByDesc('version_number')->lockForUpdate()->first();
            if ($regulation === null || $regulation->id !== (int) $data['accepted_regulation_version_id']) {
                throw ValidationException::withMessages(['accepted_regulation_version_id' => 'Zapoznaj się z aktualnym regulaminem i zaakceptuj go przed zakupem.']);
            }

            $buyer = $account === null ? null : User::query()->whereKey($account->id)->lockForUpdate()->first();
            if ($account !== null && ($buyer === null || $buyer->isSuspended() || ! $account->exists)) {
                throw new AuthorizationException('To konto nie może teraz złożyć zamówienia.');
            }
            $other = (bool) $data['for_other_person'];
            $purchaser = $data['purchaser'];
            $holder = $other ? $data['holder'] : $purchaser;
            $order = new Order;
            $order->forceFill([
                'purchaser_user_id' => $buyer?->id, 'holder_user_id' => $other ? null : $buyer?->id,
                'membership_plan_id' => $plan->id,
                'purchaser_first_name' => $purchaser['first_name'], 'purchaser_last_name' => $purchaser['last_name'],
                'purchaser_email' => $purchaser['email'], 'purchaser_phone' => $purchaser['phone'],
                'holder_first_name' => $holder['first_name'], 'holder_last_name' => $holder['last_name'],
                'holder_email' => $holder['email'], 'holder_phone' => $holder['phone'], 'recipient_email' => $holder['email'],
                'plan_snapshot' => ['snapshot_version' => 1, 'name' => $plan->name, 'kind' => $plan->kind->value,
                    'price_grosz' => $plan->price_grosz, 'currency' => $plan->currency, 'duration_months' => $plan->duration_months,
                    'included_entries' => $plan->included_entries, 'terms' => $plan->terms],
                'amount_grosz' => $plan->price_grosz, 'currency' => $plan->currency,
                'sales_channel' => SalesChannel::Online, 'payment_method' => PaymentMethod::Przelewy24, 'status' => OrderStatus::Pending,
                'ordered_at' => $now, 'paid_at' => null, 'created_by_user_id' => null,
                'accepted_regulation_version_id' => $regulation->id, 'regulation_accepted_at' => $now,
            ])->save();

            return $order;
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        foreach (['purchaser', 'holder'] as $person) {
            if (! is_array($input[$person] ?? null)) {
                continue;
            }
            foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
                $value = $input[$person][$field] ?? null;
                if (! is_string($value)) {
                    continue;
                }
                $input[$person][$field] = match ($field) {
                    'email' => mb_strtolower(trim($value)), 'phone' => PhoneNumber::normalize($value), default => trim($value),
                };
            }
        }
        $other = (bool) ($input['for_other_person'] ?? false);
        $rules = [
            'membership_plan_id' => ['required', 'integer', 'min:1'], 'plan_revision' => ['required', 'integer', 'min:0'],
            'for_other_person' => ['required', 'boolean'],
            'purchaser' => ['required', 'array:first_name,last_name,email,phone'],
            'holder' => [Rule::requiredIf($other), Rule::prohibitedIf(! $other), 'array:first_name,last_name,email,phone'],
            'accept_regulations' => ['required', 'accepted'], 'accepted_regulation_version_id' => ['required', 'integer', 'min:1'],
        ];
        foreach (['purchaser', 'holder'] as $person) {
            $required = $person === 'purchaser' || $other ? 'required' : 'sometimes';
            $rules[$person.'.first_name'] = [$required, 'string', 'max:120'];
            $rules[$person.'.last_name'] = [$required, 'string', 'max:120'];
            $rules[$person.'.email'] = [$required, 'string', 'email', 'max:254'];
            $rules[$person.'.phone'] = [$required, 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'];
        }
        foreach (['renewal_source_membership_id', 'purchaser_user_id', 'holder_user_id', 'created_by_user_id', 'amount_grosz', 'price_grosz', 'price', 'currency', 'plan_snapshot', 'status', 'sales_channel', 'payment_method', 'paid_at', 'ordered_at', 'regulation_accepted_at', 'recipient_email', 'memberships', 'items', 'quantity'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return Validator::make($input, $rules)->validate();
    }
}
