<?php

namespace App\Enums;

enum AuditAction: string
{
    case MembershipIssued = 'membership.issued';
    case MembershipActivated = 'membership.activated';
    case MembershipEntryUsed = 'membership.entry_used';
    case PriceCategoryCreated = 'price_category.created';
    case PriceCategoryUpdated = 'price_category.updated';
    case PriceCategoryDeleted = 'price_category.deleted';
    case PriceGroupCreated = 'price_group.created';
    case PriceGroupUpdated = 'price_group.updated';
    case PriceGroupDeleted = 'price_group.deleted';
    case PriceServiceCreated = 'price_service.created';
    case PriceServiceUpdated = 'price_service.updated';
    case PriceServiceDeleted = 'price_service.deleted';
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanDeleted = 'plan.deleted';
    case ClubScheduleVisibilityUpdated = 'club.schedule_visibility_updated';
    case ClubContactUpdated = 'club.contact_updated';
    case ClassTypeCreated = 'schedule.type_created';
    case ClassTypeUpdated = 'schedule.type_updated';
    case ClassTypeDeleted = 'schedule.type_deleted';
    case ClassSlotCreated = 'schedule.slot_created';
    case ClassSlotUpdated = 'schedule.slot_updated';
    case ClassSlotDeleted = 'schedule.slot_deleted';
    case GalleryCategoryCreated = 'gallery.category_created';
    case GalleryCategoryUpdated = 'gallery.category_updated';
    case GalleryCategoryDeleted = 'gallery.category_deleted';
    case GalleryCategoryReordered = 'gallery.category_reordered';
    case GalleryImageCreated = 'gallery.image_created';
    case GalleryImageUpdated = 'gallery.image_updated';
    case GalleryImageDeleted = 'gallery.image_deleted';
    case GalleryImageReordered = 'gallery.image_reordered';
    case RegulationCreated = 'regulation.created';
    case RegulationUpdated = 'regulation.updated';
    case RegulationPublished = 'regulation.published';
    case RegulationDeleted = 'regulation.deleted';
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
            self::MembershipIssued => 'Wydano opłacony karnet',
            self::MembershipActivated => 'Aktywowano karnet przy pierwszej wizycie',
            self::MembershipEntryUsed => 'Odnotowano wejście z karnetu',
            self::PriceCategoryCreated => 'Dodano kategorię cennika', self::PriceCategoryUpdated => 'Zmieniono kategorię cennika', self::PriceCategoryDeleted => 'Usunięto kategorię cennika',
            self::PriceGroupCreated => 'Dodano grupę cennika', self::PriceGroupUpdated => 'Zmieniono grupę cennika', self::PriceGroupDeleted => 'Usunięto grupę cennika',
            self::PriceServiceCreated => 'Dodano usługę do cennika', self::PriceServiceUpdated => 'Zmieniono usługę w cenniku', self::PriceServiceDeleted => 'Usunięto usługę z cennika',
            self::PlanCreated => 'Dodano plan karnetu',
            self::PlanUpdated => 'Zmieniono plan karnetu',
            self::PlanDeleted => 'Usunięto plan karnetu',
            self::ClubScheduleVisibilityUpdated => 'Zmieniono widoczność publicznego grafiku',
            self::ClubContactUpdated => 'Zmieniono kontakt i godziny klubu',
            self::ClassTypeCreated => 'Dodano rodzaj zajęć',
            self::ClassTypeUpdated => 'Zmieniono rodzaj zajęć',
            self::ClassTypeDeleted => 'Usunięto rodzaj zajęć',
            self::ClassSlotCreated => 'Dodano termin zajęć',
            self::ClassSlotUpdated => 'Zmieniono termin zajęć',
            self::ClassSlotDeleted => 'Usunięto termin zajęć',
            self::GalleryCategoryCreated => 'Dodano kategorię galerii',
            self::GalleryCategoryUpdated => 'Zmieniono kategorię galerii',
            self::GalleryCategoryDeleted => 'Usunięto kategorię galerii',
            self::GalleryCategoryReordered => 'Zmieniono kolejność kategorii galerii',
            self::GalleryImageCreated => 'Dodano zdjęcie galerii',
            self::GalleryImageUpdated => 'Zmieniono zdjęcie galerii',
            self::GalleryImageDeleted => 'Usunięto zdjęcie galerii',
            self::GalleryImageReordered => 'Zmieniono kolejność zdjęć galerii',

            self::RegulationCreated => 'Utworzono szkic regulaminu',
            self::RegulationUpdated => 'Zmieniono szkic regulaminu',
            self::RegulationPublished => 'Opublikowano regulamin',
            self::RegulationDeleted => 'Usunięto szkic regulaminu',
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
            self::MembershipIssued, self::MembershipActivated, self::MembershipEntryUsed => 'membership',
            self::PriceCategoryCreated, self::PriceCategoryUpdated, self::PriceCategoryDeleted => 'price_category',
            self::PriceGroupCreated, self::PriceGroupUpdated, self::PriceGroupDeleted => 'price_group',
            self::PriceServiceCreated, self::PriceServiceUpdated, self::PriceServiceDeleted => 'price_service',
            self::PlanCreated, self::PlanUpdated, self::PlanDeleted => 'membership_plan',
            self::ClubContactUpdated, self::ClubScheduleVisibilityUpdated => 'club_settings',
            self::ClassTypeCreated, self::ClassTypeUpdated, self::ClassTypeDeleted => 'class_type',
            self::ClassSlotCreated, self::ClassSlotUpdated, self::ClassSlotDeleted => 'class_slot',
            self::GalleryCategoryCreated, self::GalleryCategoryUpdated, self::GalleryCategoryDeleted, self::GalleryCategoryReordered => 'gallery_category',
            self::GalleryImageCreated, self::GalleryImageUpdated, self::GalleryImageDeleted, self::GalleryImageReordered => 'gallery_image',
            self::RegulationCreated, self::RegulationUpdated, self::RegulationPublished, self::RegulationDeleted => 'regulation_version',
            self::NewsCreated, self::NewsUpdated, self::NewsDeleted => 'news_post',
            self::TileCreated, self::TileUpdated, self::TileDeleted, self::TileReordered => 'homepage_tile',
            self::SlideCreated, self::SlideUpdated, self::SlideDeleted, self::SlideReordered => 'homepage_slide',
            self::MediaUploaded, self::MediaRemoved => 'media_asset',
            default => 'user',
        };
    }
}
