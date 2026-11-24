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
        | 'stopped';
    canPay: boolean;
    paymentAvailable: boolean;
    isGuest: boolean;
};
