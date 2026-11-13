<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Employee = 'employee';
    case Admin = 'admin';
}
