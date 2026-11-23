<?php

use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Models\AuditLog;
use App\Models\Membership;
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

beforeEach(function () {
    $this->fixtureMembershipIds = [];
});
afterEach(function () {
    if ($this->fixtureMembershipIds === []) {
        return;
    }
    // Dispose only the isolated test's non-expiring fixtures before schema rollback.
    $ids = $this->fixtureMembershipIds;
    DB::table('membership_entries')->whereIn('membership_id', $ids)->delete();
    DB::table('document_delivery_attempts')->whereIn('document_delivery_id', DB::table('document_deliveries')->whereIn('membership_id', $ids)->select('id'))->delete();
    DB::table('document_deliveries')->whereIn('membership_id', $ids)->delete();
    DB::table('memberships')->whereIn('id', $ids)->delete();
});

test('concurrent entry requests preserve the final balance and request idempotency', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrent entry-use row locking requires MySQL.');
    }
    $actors = User::factory()->employee()->count(2)->create();
    $memberships = [];
    foreach (range(0, $mode === 'cross-membership-uuid' ? 1 : 0) as $index) {
        $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => MembershipPlan::factory()->entries(1)->create()->id]);
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
        $membership = app(IssuePaidMembership::class)->handle($order->id, null);
        $this->fixtureMembershipIds[] = $membership->id;
        $memberships[] = app(ActivateMembership::class)->handle($actors[0], $membership->id);
    }
    $barrier = tempnam(sys_get_temp_dir(), 'entry-redemption-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $uuid = (string) Str::uuid();
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $target = $mode === 'cross-membership-uuid' ? $memberships[$worker] : $memberships[0];
            $actor = $mode === 'same-request-retry' ? $actors[0] : $actors[$worker];
            $requestId = $mode === 'last-entry' ? (string) Str::uuid() : $uuid;
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/redeem-membership-entry.php'), (string) $target->id, (string) $actor->id, $requestId, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
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
        if ($mode === 'same-request-retry') {
            expect($results[0])->toStartWith('used:')->toBe($results[1]);
        } else {
            expect(count(array_filter($results, fn (string $result): bool => str_starts_with($result, 'used:'))))->toBe(1)
                ->and(count(array_filter($results, fn (string $result): bool => $result === 'blocked')))->toBe(1);
        }
        expect(MembershipEntry::count())->toBe(1)->and(AuditLog::where('action', 'membership.entry_used')->count())->toBe(1);
        $balances = Membership::query()->orderBy('entries_remaining')->pluck('entries_remaining')->all();
        expect($balances)->toBe($mode === 'cross-membership-uuid' ? [0, 1] : [0]);
        $entry = MembershipEntry::sole();
        expect(AuditLog::where('action', 'membership.entry_used')->sole()->actor_user_id)->toBe($entry->recorded_by_user_id);
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
})->with(['last-entry', 'same-request-retry', 'cross-membership-uuid']);
