<?php

namespace App\Actions\Memberships;

use App\Models\Membership;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

class LockMembershipOrder
{
    /** Serialize uses/activation/payment replay with renewal of the same immutable lineage. */
    public function handle(Membership $locator): Order
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Membership order locks require a business transaction.');
        }
        if ($locator->renewal_root_membership_id !== null) {
            $rootOrderId = Membership::query()->findOrFail($locator->renewal_root_membership_id)->order_id;
            Order::query()->whereKey($rootOrderId)->lockForUpdate()->firstOrFail();
        }
        $order = Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
        if ($order === null) {
            throw new LogicException('Missing order for a persisted membership.');
        }

        return $order;
    }
}
