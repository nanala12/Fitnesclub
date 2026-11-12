import type { Auth, User } from './auth';

export type SharedPageProps = {
    name: string;
    businessTimezone: string;
    auth: Auth;
    sidebarOpen: boolean;
    [key: string]: unknown;
};

// Use only in views protected by Laravel's auth middleware.
export type AuthenticatedPageProps = SharedPageProps & {
    auth: { user: User };
};
