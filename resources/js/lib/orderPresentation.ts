import type { CheckoutOrder } from '@/types/checkout';

export const orderTitles: Record<CheckoutOrder['state'], string> = {
    pending_activation: 'Opłacono — karnet czeka na aktywację',
    checking_payment: 'Sprawdzamy płatność',
    awaiting_payment: 'Zamówienie oczekuje na opłatę',
    awaiting_reception: 'Oczekuje na potwierdzenie opłaty w recepcji',
    failed: 'Płatność nieudana',
    cancelled: 'Płatność przerwana lub anulowana',
    active: 'Opłacono — karnet jest aktywny',
    scheduled: 'Opłacono — przedłużenie ma ustalony termin',
    expired: 'Opłacono — okres karnetu zakończony',
    exhausted: 'Opłacono — wejścia zostały wykorzystane lub przeniesione',
};
export const salesChannels = { online: 'Online', reception: 'Recepcja' };
export const paymentMethods = {
    przelewy24: 'Przelewy24',
    cash: 'Gotówka',
    card: 'Karta w recepcji',
};
export const orderDate = (value: string) =>
    new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: 'Europe/Warsaw',
        hourCycle: 'h23',
    }).format(new Date(value));
export const businessDate = (value: string) =>
    new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'long',
        timeZone: 'Europe/Warsaw',
    }).format(new Date(value + 'T12:00:00Z'));
