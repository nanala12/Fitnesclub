<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Przelewy24 = 'przelewy24';
    case Reception = 'reception';
}
