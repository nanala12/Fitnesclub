<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageGallery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\GalleryRequest;
use App\Models\GalleryCategory;
use App\Models\GalleryContentState;
use App\Models\GalleryImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GalleryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize();
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'categories_page' => ['sometimes', 'integer', 'min:1'], 'kategoria' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return DB::transaction(function () use ($request): Response {
            $state = GalleryContentState::lock();
            $categoryId = $request->filled('kategoria') ? $request->integer('kategoria') : null;
            if ($categoryId !== null) {
                GalleryCategory::findOrFail($categoryId);
            }
            $categories = GalleryCategory::query()->withCount('images')->orderBy('sort_order')->orderBy('id')->paginate(12, pageName: 'categories_page')->withQueryString()
                ->through(fn (GalleryCategory $category): array => [...$this->categoryData($category), 'imagesCount' => $category->images_count]);
            $images = GalleryImage::query()->select('gallery_images.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY gallery_images.gallery_category_id ORDER BY gallery_images.sort_order, gallery_images.id) AS category_position, COUNT(*) OVER (PARTITION BY gallery_images.gallery_category_id) AS category_count')
                ->join('gallery_categories', 'gallery_categories.id', '=', 'gallery_images.gallery_category_id')->with(['media', 'category'])
                ->when($categoryId !== null, fn ($query) => $query->where('gallery_category_id', $categoryId))
                ->orderBy('gallery_categories.sort_order')->orderBy('gallery_categories.id')->orderBy('gallery_images.sort_order')->orderBy('gallery_images.id')->paginate(24)->withQueryString()
                ->through(fn (GalleryImage $image): array => [...$this->imageData($image), 'position' => (int) $image->getAttribute('category_position'), 'categoryCount' => (int) $image->getAttribute('category_count'), 'canMoveUp' => (int) $image->getAttribute('category_position') > 1, 'canMoveDown' => (int) $image->getAttribute('category_position') < (int) $image->getAttribute('category_count')]);

            return Inertia::render('admin/cms/gallery/Index', ['revision' => $state->revision, 'categories' => $categories, 'images' => $images, 'categoryOptions' => $this->options(), 'selectedCategory' => $categoryId]);
        });
    }

    public function categoryCreate(): Response
    {
        $this->authorize();

        return Inertia::render('admin/cms/gallery/CategoryForm', ['category' => null, 'revision' => GalleryContentState::findOrFail(1)->revision]);
    }

    public function categoryEdit(GalleryCategory $category): Response
    {
        $this->authorize();

        return DB::transaction(function () use ($category): Response {
            $state = GalleryContentState::lock();
            $category->refresh();

            return Inertia::render('admin/cms/gallery/CategoryForm', ['category' => $this->categoryData($category), 'revision' => $state->revision]);
        });
    }

    public function categoryStore(GalleryRequest $request, ManageGallery $gallery): RedirectResponse
    {
        $category = $gallery->saveCategory($request->user(), null, $this->categoryInput($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kategoria została dodana.']);

        return to_route('admin.cms.gallery.index', ['kategoria' => $category->id, 'categories_page' => max(1, (int) ceil(GalleryCategory::count() / 12))]);
    }

    public function categoryUpdate(GalleryRequest $request, GalleryCategory $category, ManageGallery $gallery): RedirectResponse
    {
        $gallery->saveCategory($request->user(), $category->id, $this->categoryInput($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kategoria została zapisana.']);

        return to_route('admin.cms.gallery.index', ['kategoria' => $category->id]);
    }

    public function categoryVisibility(GalleryRequest $request, GalleryCategory $category, ManageGallery $gallery): RedirectResponse
    {
        $gallery->visibility($request->user(), true, $category->id, $request->integer('revision'), $request->boolean('is_published'));

        return $this->redirect($request, 'Widoczność kategorii została zapisana.');
    }

    public function categoryMove(GalleryRequest $request, GalleryCategory $category, ManageGallery $gallery): RedirectResponse
    {
        $gallery->move($request->user(), true, $category->id, $request->integer('revision'), $request->validated('direction'));

        return $this->redirect($request, 'Kolejność kategorii została zapisana.');
    }

    public function categoryDestroy(GalleryRequest $request, GalleryCategory $category, ManageGallery $gallery): RedirectResponse
    {
        $gallery->delete($request->user(), true, $category->id, $request->integer('revision'));

        return $this->redirect($request, 'Kategoria została usunięta.');
    }

    public function imageCreate(Request $request): Response
    {
        $this->authorize();
        $request->validate(['kategoria' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $categoryId = $request->filled('kategoria') ? $request->integer('kategoria') : null;
        if ($categoryId !== null) {
            GalleryCategory::findOrFail($categoryId);
        }

        return Inertia::render('admin/cms/gallery/ImageForm', ['image' => null, 'revision' => GalleryContentState::findOrFail(1)->revision, 'categoryOptions' => $this->options(), 'selectedCategory' => $categoryId]);
    }

    public function imageEdit(GalleryImage $image): Response
    {
        $this->authorize();

        return DB::transaction(function () use ($image): Response {
            $state = GalleryContentState::lock();
            $image->refresh()->load(['media', 'category']);

            return Inertia::render('admin/cms/gallery/ImageForm', ['image' => $this->imageData($image), 'revision' => $state->revision, 'categoryOptions' => $this->options(), 'selectedCategory' => $image->gallery_category_id]);
        });
    }

    public function imageStore(GalleryRequest $request, ManageGallery $gallery): RedirectResponse
    {
        $image = $gallery->saveImage($request->user(), null, $this->imageInput($request), $this->file($request));
        if ($request->boolean('continue_upload')) {
            return to_route('admin.cms.gallery.images.create', ['kategoria' => $image->gallery_category_id]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie zostało dodane.']);
        $page = max(1, (int) ceil(GalleryImage::where('gallery_category_id', $image->gallery_category_id)->count() / 24));

        return to_route('admin.cms.gallery.index', ['kategoria' => $image->gallery_category_id, 'page' => $page]);
    }

    public function imageUpdate(GalleryRequest $request, GalleryImage $image, ManageGallery $gallery): RedirectResponse
    {
        $saved = $gallery->saveImage($request->user(), $image->id, $this->imageInput($request), $this->file($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie zostało zapisane.']);

        return to_route('admin.cms.gallery.index', ['kategoria' => $saved->gallery_category_id]);
    }

    public function imageVisibility(GalleryRequest $request, GalleryImage $image, ManageGallery $gallery): RedirectResponse
    {
        $gallery->visibility($request->user(), false, $image->id, $request->integer('revision'), $request->boolean('is_published'));

        return $this->redirect($request, 'Widoczność zdjęcia została zapisana.');
    }

    public function imageMove(GalleryRequest $request, GalleryImage $image, ManageGallery $gallery): RedirectResponse
    {
        $gallery->move($request->user(), false, $image->id, $request->integer('revision'), $request->validated('direction'));

        return $this->redirect($request, 'Kolejność zdjęć została zapisana.');
    }

    public function imageDestroy(GalleryRequest $request, GalleryImage $image, ManageGallery $gallery): RedirectResponse
    {
        $gallery->delete($request->user(), false, $image->id, $request->integer('revision'), $request->integer('current_media_id'));

        return $this->redirect($request, 'Zdjęcie zostało usunięte.');
    }

    private function authorize(): void
    {
        Gate::authorize('manage', GalleryCategory::class);
    }

    /** @return array<string,mixed> */
    private function categoryData(GalleryCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'description' => $category->short_description, 'is_published' => $category->is_published];
    }

    /** @return array<string,mixed> */
    private function imageData(GalleryImage $image): array
    {
        return ['id' => $image->id, 'categoryId' => $image->gallery_category_id, 'categoryName' => $image->category->name, 'categoryPublished' => $image->category->is_published, 'is_published' => $image->is_published,
            'image' => ['id' => $image->media->id, 'src' => route('admin.cms.media.show', $image->media->id), 'alt' => $image->media->alt_text ?? '']];
    }

    /** @return array<int,array<string,mixed>> */
    private function options(): array
    {
        return GalleryCategory::orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_published'])->map(fn (GalleryCategory $category): array => ['id' => $category->id, 'name' => $category->name, 'is_published' => $category->is_published])->all();
    }

    /** @return array{name:string,short_description:?string,is_published:bool,revision:int} */
    private function categoryInput(GalleryRequest $request): array
    {
        return ['name' => $request->validated('name'), 'short_description' => $request->validated('description'), 'is_published' => $request->boolean('is_published'), 'revision' => $request->integer('revision')];
    }

    /** @return array{gallery_category_id:int,is_published:bool,revision:int,current_media_id:?int,alt_text:?string} */
    private function imageInput(GalleryRequest $request): array
    {
        return ['gallery_category_id' => $request->integer('gallery_category_id'), 'is_published' => $request->boolean('is_published'), 'revision' => $request->integer('revision'),
            'current_media_id' => $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'), 'alt_text' => $request->validated('alt_text')];
    }

    private function file(GalleryRequest $request): ?UploadedFile
    {
        $file = $request->file('image');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function redirect(Request $request, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        $categoryId = $request->filled('kategoria') && GalleryCategory::whereKey($request->integer('kategoria'))->exists() ? $request->integer('kategoria') : null;
        $count = GalleryImage::when($categoryId !== null, fn ($query) => $query->where('gallery_category_id', $categoryId))->count();

        return to_route('admin.cms.gallery.index', array_filter(['kategoria' => $categoryId, 'page' => min($request->integer('page', 1), max(1, (int) ceil($count / 24))),
            'categories_page' => min($request->integer('categories_page', 1), max(1, (int) ceil(GalleryCategory::count() / 12)))], fn ($value) => $value !== null));
    }
}
