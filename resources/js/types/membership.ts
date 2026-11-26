import type { CheckoutOrder } from './checkout';
export type OwnMembership = {
    id: number;
    holderName: string;
    forAnotherPerson: boolean;
    planName: string;
    state: CheckoutOrder['state'];
    startsOn: string | null;
    expiresOn: string | null;
    entriesRemaining: number | null;
    purchasedAt: string;
    pdfUrl: string | null;
    showUrl: string;
};
