<?php

namespace App\Enums;

enum AuditAction: string
{
    case EmployeeCreated = 'employee.created';
    case EmployeeUpdated = 'employee.updated';
    case EmployeeSuspended = 'employee.suspended';
    case EmployeeResumed = 'employee.resumed';
    case EmployeeInvitationRequested = 'employee.invitation_requested';

    public function label(): string
    {
        return match ($this) {
            self::EmployeeCreated => 'Utworzono konto zespołu',
            self::EmployeeUpdated => 'Zmieniono dane konta zespołu',
            self::EmployeeSuspended => 'Zawieszono dostęp do konta',
            self::EmployeeResumed => 'Przywrócono dostęp do konta',
            self::EmployeeInvitationRequested => 'Zlecono ponowną wysyłkę zaproszenia',
        };
    }

    public function subjectType(): string
    {
        return 'user';
    }
}
