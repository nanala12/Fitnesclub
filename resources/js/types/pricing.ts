import type { PaginationLink } from './pagination';
export type PriceCategory = { value: string; label: string };
export type PublicPriceCategory = {
    id: string;
    name: string;
    category: string;
    items: { id: string; name: string; priceLabel: string }[];
};
export type AdminPriceItem = {
    id: number;
    name: string;
    is_published: boolean;
    sort_order: number;
    revision: number;
    key?: string;
    category?: string;
    categoryLabel?: string;
    categoryPublished?: boolean;
    price?: string;
    priceLabel?: string;
};
export type PricePagination = {
    current_page: number;
    data: AdminPriceItem[];
    total: number;
    links: PaginationLink[];
};
