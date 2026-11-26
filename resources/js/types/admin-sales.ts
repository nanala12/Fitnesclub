import type { CheckoutOrder } from './checkout';
export type SalesOrder = CheckoutOrder & {
    holderName: string;
    purchaserName: string;
};
export type SalesFilters = {
    channel?: string | null;
    method?: string | null;
    status?: string | null;
    holder_account?: number;
    purchaser_account?: number;
    root?: number;
    page?: number;
};

export type PaymentMethod = CheckoutOrder['paymentMethod'];
