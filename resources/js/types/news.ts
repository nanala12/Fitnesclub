import type { PublicImage } from './public';
export type AdminNewsPost = {
    id: number;
    title: string;
    excerpt: string | null;
    body?: string;
    revision: number;
    is_published: boolean;
    publishedAt: string | null;
    publicationLocal: string | null;
    status: 'draft' | 'scheduled' | 'published';
    publicUrl: string | null;
    image: PublicImage | null;
};
