<?php

namespace App\Http\Controllers\PublicSite;

use App\Actions\PublicSite\PublicSiteContent;
use App\Http\Controllers\Controller;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\NewsPost;
use App\Models\OfferTile;
use App\Support\Cms\RichTextSanitizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function offer(PublicSiteContent $content): Response
    {
        return Inertia::render('public/Offer', [...$content->shared('offer'), 'tiles' => $content->offers()]);
    }

    public function offerShow(int $offerTile, PublicSiteContent $content): Response
    {
        $tile = OfferTile::query()->with('media')->where('is_published', true)->whereKey($offerTile)->firstOrFail();

        return Inertia::render('public/OfferShow', [...$content->shared('offer.show', $tile), 'offer' => $content->offerDetail($tile)]);
    }

    public function pricing(PublicSiteContent $content): Response
    {
        // Membership plans have one source, introduced by TASK-019/020; never copy demo prices.
        return Inertia::render('public/Pricing', [...$content->shared('pricing'), 'plans' => []]);
    }

    public function news(PublicSiteContent $content): Response
    {
        return Inertia::render('public/News/Index', [...$content->shared('news'),
            'posts' => $content->publishedNews()->with('media')->orderByDesc('published_at')->orderByDesc('id')->paginate(9)->through(fn (NewsPost $post): array => $content->newsCard($post))]);
    }

    public function newsShow(string $slug, PublicSiteContent $content): Response
    {
        $post = $content->publishedNews()->with('media')->where('slug', $slug)->firstOrFail();

        return Inertia::render('public/News/Show', [...$content->shared('news.show', $post), 'post' => [...$content->newsCard($post), 'bodyHtml' => app(RichTextSanitizer::class)->clean($post->body), 'slug' => $post->slug]]);
    }

    public function gallery(Request $request, PublicSiteContent $content): Response
    {
        $validated = $request->validate(['kategoria' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $categoryId = isset($validated['kategoria']) ? (int) $validated['kategoria'] : null;
        if ($categoryId !== null) {
            GalleryCategory::where('is_published', true)->findOrFail($categoryId);
        }
        $categories = GalleryCategory::where('is_published', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'short_description']);
        $images = GalleryImage::with('media')->select('gallery_images.*')->join('gallery_categories', 'gallery_categories.id', '=', 'gallery_images.gallery_category_id')->where('gallery_images.is_published', true)->whereIn('gallery_category_id', $categories->pluck('id'))
            ->when($categoryId !== null, fn ($query) => $query->where('gallery_category_id', $categoryId))->orderBy('gallery_categories.sort_order')->orderBy('gallery_categories.id')->orderBy('gallery_images.sort_order')->orderBy('gallery_images.id')->paginate(24)->withQueryString()
            ->through(fn (GalleryImage $image): array => ['id' => $image->id, 'categoryId' => $image->gallery_category_id, 'image' => $content->image($image->media)]);

        return Inertia::render('public/Gallery', [...$content->shared('gallery'), 'categories' => $categories->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name, 'description' => $content->text($category->short_description)]), 'images' => $images, 'selectedCategory' => $categoryId]);
    }

    public function contact(PublicSiteContent $content): Response
    {
        return Inertia::render('public/Contact', $content->shared('contact'));
    }

    public function schedule(PublicSiteContent $content): Response
    {
        return Inertia::render('public/Schedule', [...$content->shared('schedule'), 'slots' => $content->schedule()]);
    }

    public function regulations(PublicSiteContent $content): Response
    {
        $version = $content->publishedRegulations()->first();

        return Inertia::render('public/Regulations', [...$content->shared('regulations'), 'regulation' => $version === null ? null : [
            'id' => $version->id, 'versionNumber' => $version->version_number, 'publishedAt' => $version->published_at?->toISOString(),
            'bodyHtml' => app(RichTextSanitizer::class)->clean($version->body), 'image' => $content->image($version->media),
        ]]);
    }
}
