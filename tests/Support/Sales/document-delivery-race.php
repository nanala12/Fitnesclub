<?php

use App\Actions\Documents\DeliverMembershipDocument;
use App\Actions\Documents\RequestDocumentResend;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $deliveryId, $barrier, $worker, $mode, $adminId, $requestId] = $argv;
config(['filesystems.disks.membership_documents.root' => $barrier.'.documents', 'mail.mailers.membership.transport' => 'array']);
Http::preventStrayRequests();
Event::listen(MessageSending::class, function () use ($barrier, $worker): void {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Mail transport cannot hold a transaction.');
    }
    touch($barrier.'.sending.'.$worker);
    $deadline = microtime(true) + 12;
    while (! file_exists($barrier.'.release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test transport release timed out.');
        }
        usleep(10000);
    }
});
Event::listen(MessageSent::class, fn () => file_put_contents($barrier.'.sent.'.$worker, 'sent'));
touch($barrier.'.ready.'.$worker);
$deadline = microtime(true) + 12;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test start barrier timed out.');
    }
    usleep(10000);
}
if ($mode === 'resend') {
    echo app(RequestDocumentResend::class)->handle(User::findOrFail((int) $adminId), (int) $deliveryId, $requestId)->id;
} else {
    app(DeliverMembershipDocument::class)->handle((int) $deliveryId, 0);
    echo 'completed';
}
touch($barrier.'.done.'.$worker);
