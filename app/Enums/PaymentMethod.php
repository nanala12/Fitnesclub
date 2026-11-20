<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Przelewy24 = 'przelewy24';
    case Cash = 'cash';
    case Card = 'card';
}
