import type { PaginationLink } from './pagination';
export type PublicImage = { id: number; src: string; alt: string };
export type ClubInfo = {
    name: string | null;
    email: string | null;
    phone: string | null;
    phoneHref: string | null;
    address: string | null;
    postalCode: string | null;
    city: string | null;
    mapUrl: string | null;
};
export type OpeningHour = {
    weekday: number;
    label: string;
    closed: boolean;
    opensAt: string | null;
    closesAt: string | null;
};
export type OfferTile = {
    id: number;
    title: string;
    description: string;
    image: PublicImage | null;
    href: string;
};
export type HeroSlide = {
    id: number;
    title: string | null;
    description: string;
    image: PublicImage | null;
};
export type NewsCard = {
    id: number;
    title: string;
    excerpt: string;
    href: string;
    publishedAt: string;
    image: PublicImage | null;
};
export type PublicPagination<T> = {
    data: T[];
    links: PaginationLink[];
    total: number;
};
