export type AdminMembershipPlan = {
    id: number;
    name: string;
    description: string | null;
    kind: 'time' | 'entries';
    price: string;
    priceLabel: string;
    duration_months: number | null;
    included_entries: number | null;
    is_active: boolean;
    is_public: boolean;
    is_featured: boolean;
    sort_order: number;
    revision: number;
    hasHistory: boolean;
    category: string;
};

export type PublicMembershipPlan = {
    category: string;
    sortOrder: number;
    id: number;
    name: string;
    description: string | null;
    kind: 'time' | 'entries';
    priceLabel: string;
    durationMonths: number | null;
    includedEntries: number | null;
};
