<?php

namespace App\Actions\Orders;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateReceptionOrder
{
    public function __construct(private RecordAudit $audit) {}

    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): Order
    {
        return DB::transaction(function () use ($actor, $input): Order {
            // Catalog first matches online creation and prevents duplicate request-index gap races.
            DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
            if (! $actor->exists) {
                throw new AuthorizationException;
            }
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($actor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('createReception', Order::class);
            $data = $this->validate($input);
            $buyer = isset($data['purchaser_user_id']) ? (int) $data['purchaser_user_id'] : null;
            $holderId = $data['for_other_person'] ? (isset($data['holder_user_id']) ? (int) $data['holder_user_id'] : null) : $buyer;
            $holder = $data['for_other_person'] ? $data['holder'] : $data['purchaser'];
            $attributes = [
                'membership_plan_id' => (int) $data['membership_plan_id'], 'purchaser_user_id' => $buyer, 'holder_user_id' => $holderId,
                'purchaser_first_name' => $data['purchaser']['first_name'], 'purchaser_last_name' => $data['purchaser']['last_name'],
                'purchaser_email' => $data['purchaser']['email'], 'purchaser_phone' => $data['purchaser']['phone'],
                'holder_first_name' => $holder['first_name'], 'holder_last_name' => $holder['last_name'],
                'holder_email' => $holder['email'], 'holder_phone' => $holder['phone'], 'recipient_email' => $holder['email'],
                'payment_method' => $data['payment_method'],
                'renewal_source_membership_id' => isset($data['renewal_source_membership_id']) ? (int) $data['renewal_source_membership_id'] : null,
            ];
            $existing = Order::query()->where('reception_request_id', $data['request_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                $matches = $existing->created_by_user_id === $actor->id && ($existing->plan_snapshot['catalog_revision'] ?? null) === (int) $data['plan_revision'];
                foreach ($attributes as $field => $value) {
                    $stored = $field === 'payment_method' ? $existing->payment_method->value : $existing->getAttribute($field);
                    $matches = $matches && $stored === $value;
                }
                if (! $matches) {
                    throw ValidationException::withMessages(['request_id' => 'Ten identyfikator jest już przypisany do innej sprzedaży. Odśwież formularz przed nową operacją.']);
                }

                return $existing;
            }
            $plan = MembershipPlan::query()->whereKey($data['membership_plan_id'])->lockForUpdate()->first();
            if ($plan === null || ! $plan->is_active) {
                throw ValidationException::withMessages(['membership_plan_id' => 'Ten karnet nie jest teraz dostępny do sprzedaży.']);
            }
            if ($plan->revision !== (int) $data['plan_revision']) {
                throw ValidationException::withMessages(['plan_revision' => 'Warunki karnetu zostały zmienione. Sprawdź aktualną ofertę.']);
            }
            $ids = array_values(array_unique(array_filter([$buyer, $holderId], fn (?int $id): bool => $id !== null)));
            $accounts = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->all();
            if (count($accounts) !== count($ids)) {
                throw ValidationException::withMessages(['holder_user_id' => 'Wybrane konto nie jest już dostępne. Wybierz właściwą osobę.']);
            }
            $order = new Order;
            $order->forceFill([...$attributes, 'payment_method' => PaymentMethod::from($data['payment_method']),
                'reception_request_id' => $data['request_id'], 'sales_channel' => SalesChannel::Reception,
                'status' => OrderStatus::Pending, 'created_by_user_id' => $actor->id, 'ordered_at' => CarbonImmutable::now('UTC'), 'paid_at' => null,
                'amount_grosz' => $plan->price_grosz, 'currency' => $plan->currency,
                'plan_snapshot' => ['snapshot_version' => 1, 'catalog_revision' => $plan->revision, 'name' => $plan->name, 'kind' => $plan->kind->value,
                    'price_grosz' => $plan->price_grosz, 'currency' => $plan->currency, 'duration_months' => $plan->duration_months,
                    'included_entries' => $plan->included_entries, 'terms' => $plan->terms],
                'accepted_regulation_version_id' => null, 'regulation_accepted_at' => null,
            ])->save();
            $this->audit->handle(AuditAction::ReceptionOrderCreated, $order->id, $actor, null,
                ['price_grosz' => $order->amount_grosz, 'payment_method' => $order->payment_method->value, 'sales_channel' => 'reception'], ['request_id' => $data['request_id']]);

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
                if (is_string($value)) {
                    $input[$person][$field] = match ($field) {
                        'phone' => PhoneNumber::normalize($value), 'email' => mb_strtolower(trim($value)), default => trim($value),
                    };
                }
            }
        }
        if (is_string($input['request_id'] ?? null)) {
            $input['request_id'] = strtolower($input['request_id']);
        }
        foreach (['purchaser_user_id', 'holder_user_id', 'renewal_source_membership_id'] as $field) {
            if (is_string($input[$field] ?? null) && trim($input[$field]) === '') {
                $input[$field] = null;
            }
        }
        $other = (bool) ($input['for_other_person'] ?? false);
        $rules = [
            'request_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D'],
            'membership_plan_id' => ['required', 'integer', 'min:1'], 'plan_revision' => ['required', 'integer', 'min:0'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'card'])], 'for_other_person' => ['required', 'boolean'],
            'purchaser' => ['required', 'array:first_name,last_name,email,phone'],
            'holder' => [Rule::requiredIf($other), Rule::prohibitedIf(! $other), 'array:first_name,last_name,email,phone'],
            'purchaser_user_id' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'holder_user_id' => [Rule::prohibitedIf(! $other), 'nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'renewal_source_membership_id' => ['nullable', 'integer', 'min:1', Rule::exists('memberships', 'id')],
        ];
        foreach (['purchaser', 'holder'] as $person) {
            $required = $person === 'purchaser' || $other ? 'required' : 'sometimes';
            $rules[$person.'.first_name'] = [$required, 'string', 'max:120'];
            $rules[$person.'.last_name'] = [$required, 'string', 'max:120'];
            $rules[$person.'.email'] = [$required, 'string', 'email', 'max:254'];
            $rules[$person.'.phone'] = [$required, 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'];
        }
        foreach (['created_by_user_id', 'confirmed_by_user_id', 'amount_grosz', 'price_grosz', 'price', 'currency', 'plan_snapshot', 'status', 'sales_channel', 'paid_at', 'confirmed_at', 'ordered_at', 'recipient_email', 'accepted_regulation_version_id', 'regulation_accepted_at', 'accept_regulations', 'items', 'quantity', 'reception_request_id'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return Validator::make($input, $rules)->validate();
    }
}
