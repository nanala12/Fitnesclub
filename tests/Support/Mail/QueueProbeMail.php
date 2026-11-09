<?php

namespace Tests\Support\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class QueueProbeMail extends Mailable
{
    use Queueable;

    public function build(): static
    {
        return $this->subject('Environment check')->html('<p>Local queue delivery check.</p>');
    }
}
