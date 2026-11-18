export type HomepageSection = 'kafelki' | 'karuzela';
export type HomepageImage = { id: number; src: string; alt: string };
export type HomepageItem = {
    id: number;
    title: string | null;
    description: string | null;
    link_url: string | null;
    is_published: boolean;
    image: HomepageImage | null;
};
