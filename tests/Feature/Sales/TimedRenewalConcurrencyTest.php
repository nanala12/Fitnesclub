<?php

use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }
    // Dispose only this isolated test's renewal fixtures before reversing the lineage schema.
    $ids = DB::table('memberships')->whereNotNull('renewal_root_membership_id')->orderByDesc('id')->pluck('id');
    DB::table('orders')->whereNotNull('renewal_source_membership_id')->update(['renewal_source_membership_id' => null]);
    DB::table('document_deliveries')->whereIn('membership_id', $ids)->delete();
    foreach ($ids as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

test('concurrent early renewals serialize future periods and duplicate confirmations', function (bool $sameOrder) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Renewal chain locks require MySQL.');
    }
    $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->addHours(12));
    $plan = MembershipPlan::factory()->create();
    $actors = User::factory()->employee()->count(2)->create();
    $makeOrder = function () use ($plan, $actors): Order {
        $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'created_by_user_id' => $actors[0]->id]);
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actors[0]->id]);

        return $order;
    };
    $sourceOrder = $makeOrder();
    $source = app(IssuePaidMembership::class)->handle($sourceOrder->id, null);
    app(ActivateMembership::class)->handle($actors[0], $source->id);
    $firstOrder = $makeOrder();
    $secondOrder = $sameOrder ? $firstOrder : $makeOrder();
    $barrier = tempnam(sys_get_temp_dir(), 'renewal-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([$firstOrder, $secondOrder] as $worker => $order) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/renew-membership.php'), (string) $order->id, (string) $source->id, (string) $actors[$worker]->id, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
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
        expect(Membership::count())->toBe($sameOrder ? 2 : 3)->and(DocumentDelivery::count())->toBe($sameOrder ? 2 : 3)
            ->and(AuditLog::where('action', 'membership.renewed')->count())->toBe($sameOrder ? 1 : 2);
        if ($sameOrder) {
            expect($results[0])->toBe($results[1]);
        } else {
            $renewals = Membership::query()->whereNotNull('renewal_root_membership_id')->orderBy('starts_on')->get();
            expect($renewals[1]->starts_on->toDateString())->toBe($renewals[0]->expires_on->addDay()->toDateString())
                ->and($renewals[1]->renewed_from_id)->toBe($renewals[0]->id);
        }
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
})->with([false, true]);
