export type CheckoutPerson = {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
};

export type CheckoutPlan = {
    id: number;
    revision: number;
    name: string;
    description: string | null;
    kind: 'time' | 'entries';
    priceLabel: string;
    durationMonths: number | null;
    includedEntries: number | null;
};

export type CheckoutOrder = {
    reference: string;
    planName: string;
    priceLabel: string;
    state:
        | 'pending_activation'
        | 'checking_payment'
        | 'awaiting_payment'
        | 'failed'
        | 'cancelled'
        | 'awaiting_reception'
        | 'active'
        | 'scheduled'
        | 'expired'
        | 'exhausted';
    paid: boolean;
    salesChannel: 'online' | 'reception';
    paymentMethod: 'przelewy24' | 'cash' | 'card';
    orderedAt: string;
    paidAt: string | null;
    startsOn: string | null;
    expiresOn: string | null;
    entriesRemaining: number | null;
    showUrl: string;
    canPay: boolean;
    paymentAvailable: boolean;
    isGuest: boolean;
    pdfUrl: string | null;
};
