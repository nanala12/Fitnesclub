export const contactFields = [
    { key: 'club_name', label: 'Nazwa klubu', max: 150, type: 'text' },
    { key: 'contact_email', label: 'Adres e-mail', max: 255, type: 'email' },
    { key: 'contact_phone', label: 'Telefon', max: 30, type: 'tel' },
    { key: 'street_address', label: 'Ulica i numer', max: 255, type: 'text' },
    { key: 'postal_code', label: 'Kod pocztowy', max: 20, type: 'text' },
    { key: 'city', label: 'Miejscowość', max: 150, type: 'text' },
    {
        key: 'map_url',
        label: 'Odnośnik do mapy (HTTPS)',
        max: 1000,
        type: 'url',
    },
] as const;
export type ContactKey = (typeof contactFields)[number]['key'];
export type ClubContact = Record<ContactKey, string | null>;
export type OpeningDay = {
    weekday: number;
    label: string;
    status: 'unknown' | 'open' | 'closed';
    opens_at: string;
    closes_at: string;
};
export type ClassTypeItem = {
    id: number;
    name: string;
    description: string | null;
    is_published: boolean;
    slotsCount?: number;
};
export type ClassSlot = {
    id: number;
    class_type_id: number;
    weekday: number;
    starts_at: string;
    ends_at: string;
    instructor_name: string | null;
    is_published: boolean;
};
export type ClassSlotItem = ClassSlot & {
    typeName: string;
    typePublished: boolean;
    dayLabel: string;
};
