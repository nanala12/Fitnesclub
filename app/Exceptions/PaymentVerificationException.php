<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentVerificationException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly bool $retryable = false)
    {
        parent::__construct('Nie można teraz potwierdzić tej płatności.');
    }
}
