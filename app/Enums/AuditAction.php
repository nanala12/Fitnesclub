<?php

namespace App\Enums;

enum AuditAction: string
{
    case MediaUploaded = 'media.uploaded';
    case MediaRemoved = 'media.removed';
    case EmployeeCreated = 'employee.created';
    case EmployeeUpdated = 'employee.updated';
    case EmployeeSuspended = 'employee.suspended';
    case EmployeeResumed = 'employee.resumed';
    case EmployeeInvitationRequested = 'employee.invitation_requested';

    public function label(): string
    {
        return match ($this) {
            self::MediaUploaded => 'Dodano zdjęcie CMS',
            self::MediaRemoved => 'Usunięto zdjęcie CMS',
            self::EmployeeCreated => 'Utworzono konto zespołu',
            self::EmployeeUpdated => 'Zmieniono dane konta zespołu',
            self::EmployeeSuspended => 'Zawieszono dostęp do konta',
            self::EmployeeResumed => 'Przywrócono dostęp do konta',
            self::EmployeeInvitationRequested => 'Zlecono ponowną wysyłkę zaproszenia',
        };
    }

    public function subjectType(): string
    {
        return match ($this) {
            self::MediaUploaded, self::MediaRemoved => 'media_asset',
            default => 'user',
        };
    }
}
