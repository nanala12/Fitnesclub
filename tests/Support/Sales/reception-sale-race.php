<?php

use App\Actions\Orders\CreateReceptionOrder;
use App\Actions\Payments\ConfirmReceptionPayment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $mode, $actorId, $planId, $orderId, $requestId, $barrier, $worker] = $argv;
$id = DB::transaction(function () use ($mode, $actorId, $planId, $orderId, $requestId, $barrier, $worker): int {
    Order::query()->get(['id']);
    touch($barrier.'.'.$worker);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }
    $actor = User::findOrFail((int) $actorId);
    if ($mode === 'confirm') {
        return app(ConfirmReceptionPayment::class)->handle($actor, (int) $orderId)->id;
    }
    $order = app(CreateReceptionOrder::class)->handle($actor, [
        'request_id' => $requestId, 'membership_plan_id' => (int) $planId, 'plan_revision' => 0,
        'payment_method' => 'cash', 'for_other_person' => false,
        'purchaser' => ['first_name' => 'Ania', 'last_name' => 'Testowa', 'email' => 'buyer@example.test', 'phone' => '+48600111222'],
    ]);

    return $mode === 'complete' ? app(ConfirmReceptionPayment::class)->handle($actor, $order)->id : $order->id;
}, 3);
echo $id;
