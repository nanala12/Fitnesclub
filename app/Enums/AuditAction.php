<?php

namespace App\Enums;

enum AuditAction: string
{
    case NewsCreated = 'news.created';
    case NewsUpdated = 'news.updated';
    case NewsDeleted = 'news.deleted';
    case TileCreated = 'homepage.tile_created';
    case TileUpdated = 'homepage.tile_updated';
    case TileDeleted = 'homepage.tile_deleted';
    case TileReordered = 'homepage.tile_reordered';
    case SlideCreated = 'homepage.slide_created';
    case SlideUpdated = 'homepage.slide_updated';
    case SlideDeleted = 'homepage.slide_deleted';
    case SlideReordered = 'homepage.slide_reordered';
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
            self::NewsCreated => 'Dodano aktualność',
            self::NewsUpdated => 'Zmieniono aktualność',
            self::NewsDeleted => 'Usunięto aktualność',
            self::TileCreated => 'Dodano kafelek oferty',
            self::TileUpdated => 'Zmieniono kafelek oferty',
            self::TileDeleted => 'Usunięto kafelek oferty',
            self::TileReordered => 'Zmieniono kolejność kafelków',
            self::SlideCreated => 'Dodano slajd karuzeli',
            self::SlideUpdated => 'Zmieniono slajd karuzeli',
            self::SlideDeleted => 'Usunięto slajd karuzeli',
            self::SlideReordered => 'Zmieniono kolejność slajdów',
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
            self::NewsCreated, self::NewsUpdated, self::NewsDeleted => 'news_post',
            self::TileCreated, self::TileUpdated, self::TileDeleted, self::TileReordered => 'homepage_tile',
            self::SlideCreated, self::SlideUpdated, self::SlideDeleted, self::SlideReordered => 'homepage_slide',
            self::MediaUploaded, self::MediaRemoved => 'media_asset',
            default => 'user',
        };
    }
}
