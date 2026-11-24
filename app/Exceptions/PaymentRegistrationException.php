<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRegistrationException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly bool $uncertain)
    {
        parent::__construct('Nie udało się przygotować płatności. Spróbuj ponownie później lub skontaktuj się z klubem.');
    }
}
