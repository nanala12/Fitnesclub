<?php

namespace App\Jobs;

use App\Actions\Documents\DeliverMembershipDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMembershipDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId, public int $expectedAttempts)
    {
        $this->onConnection('database')->onQueue('default')->afterCommit();
    }

    public function handle(DeliverMembershipDocument $deliver): void
    {
        $deliver->handle($this->deliveryId, $this->expectedAttempts);
    }
}
