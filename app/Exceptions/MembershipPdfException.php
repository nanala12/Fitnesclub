<?php

namespace App\Exceptions;

use RuntimeException;

class MembershipPdfException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Nie można teraz przygotować PDF karnetu. Spróbuj ponownie za chwilę.');
    }
}
