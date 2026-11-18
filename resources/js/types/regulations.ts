export type RegulationItem = {
    id: number;
    versionNumber: number;
    publishedAt: string | null;
    status: 'draft' | 'current' | 'history';
    canPublish: boolean;
};
export type RegulationDetail = {
    id: number;
    versionNumber: number;
    publishedAt: string | null;
    body: string;
    image: { id: number; src: string; alt: string } | null;
};
