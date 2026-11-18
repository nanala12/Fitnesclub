import type { PublicImage } from './public';
export type GalleryCategory = {
    id: number;
    name: string;
    description: string | null;
    is_published: boolean;
    imagesCount?: number;
};
export type GalleryCategoryOption = Pick<
    GalleryCategory,
    'id' | 'name' | 'is_published'
>;
export type GalleryPhoto = {
    id: number;
    categoryId: number;
    categoryName: string;
    categoryPublished: boolean;
    is_published: boolean;
    image: PublicImage;
    position?: number;
    canMoveUp?: boolean;
    canMoveDown?: boolean;
};
