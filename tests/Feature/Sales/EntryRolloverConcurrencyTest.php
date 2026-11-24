<?php

use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Memberships\RenewPaidMembership;
use App\Models\Membership;
use App\Models\MembershipAdjustment;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }
    // These tables contain only this isolated test's fixtures, including non-expiring roots.
    DB::table('orders')->whereNotNull('renewal_source_membership_id')->update(['renewal_source_membership_id' => null]);
    DB::table('membership_entries')->delete();
    DB::table('membership_adjustments')->delete();
    DB::table('document_delivery_attempts')->delete();
    DB::table('document_deliveries')->delete();
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

test('rollover races preserve entries across purchases retries and simultaneous use', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Rollover row-lock races require MySQL.');
    }
    $actors = User::factory()->employee()->count(2)->create();
    $plan = MembershipPlan::factory()->entries(8)->create();
    $makeOrder = function () use ($actors, $plan): Order {
        $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'created_by_user_id' => $actors[0]->id]);
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actors[0]->id]);

        return $order;
    };
    $source = app(IssuePaidMembership::class)->handle($makeOrder()->id, null);
    app(ActivateMembership::class)->handle($actors[0], $source->id);
    for ($index = 0; $index < 5; $index++) {
        app(RedeemMembershipEntry::class)->handle($actors[0], $source->id, (string) Str::uuid());
    }
    $useTarget = $source;
    $initialAdjustments = 0;
    if ($mode === 'tail-use') {
        $useTarget = app(RenewPaidMembership::class)->handle($actors[0], $makeOrder()->id, $source->id);
        $initialAdjustments = 2;
    }
    $firstOrder = $makeOrder();
    $secondOrder = $mode === 'duplicate' ? $firstOrder : $makeOrder();
    $barrier = tempnam(sys_get_temp_dir(), 'rollover-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([$firstOrder, $secondOrder] as $worker => $order) {
            $operation = $worker === 1 && in_array($mode, ['source-use', 'tail-use'], true) ? 'use' : 'renew';
            $memberId = $operation === 'use' ? $useTarget->id : $source->id;
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/rollover-race.php'), $operation, (string) $order->id, (string) $memberId, (string) $actors[$worker]->id, (string) Str::uuid(), $barrier, (string) $worker], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.0') && file_exists($barrier.'.1'))->toBeTrue();
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        $balances = Membership::query()->sum('entries_remaining');
        if ($mode === 'two-purchases') {
            expect((int) $balances)->toBe(19)->and(MembershipAdjustment::count())->toBe(4)->and(Membership::count())->toBe(3);
        } elseif ($mode === 'duplicate') {
            expect($results[0])->toBe($results[1])->and((int) $balances)->toBe(11)->and(MembershipAdjustment::count())->toBe(2);
        } else {
            $uses = MembershipEntry::count() - 5;
            expect((int) $balances + $uses)->toBe($mode === 'tail-use' ? 19 : 11)
                ->and(MembershipAdjustment::count())->toBe($initialAdjustments + 2);
        }
        expect($source->fresh()->entries_remaining)->toBe(0)->and(Membership::query()->where('entries_remaining', '<', 0)->exists())->toBeFalse();
        expect((int) MembershipAdjustment::query()->sum('delta_entries'))->toBe(0);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.0', $barrier.'.1'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
})->with(['two-purchases', 'duplicate', 'source-use', 'tail-use']);
