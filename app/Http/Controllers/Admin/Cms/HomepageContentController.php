<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageHomepageContent;
use App\Enums\HomepageSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\HomepageContentRequest;
use App\Models\HeroSlide;
use App\Models\HomepageContentList;
use App\Models\OfferTile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HomepageContentController extends Controller
{
    public function index(Request $request, HomepageSection $section): Response
    {
        Gate::authorize('manage', $section->model());
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return DB::transaction(function () use ($section): Response {
            $list = HomepageContentList::lock($section);
            $items = $section->model()::query()->with('media')->orderBy('sort_order')->orderBy('id')->paginate(20);

            return Inertia::render('admin/cms/homepage/Index', [
                'section' => $section->value, 'revision' => $list->revision,
                'items' => $items->through(fn (OfferTile|HeroSlide $item): array => $this->present($item, $section)),
            ]);
        });
    }

    public function create(HomepageSection $section): Response
    {
        Gate::authorize('manage', $section->model());

        return Inertia::render('admin/cms/homepage/Form', ['section' => $section->value, 'revision' => HomepageContentList::findOrFail($section->value)->revision, 'item' => null]);
    }

    public function edit(HomepageSection $section, int $item): Response
    {
        Gate::authorize('manage', $section->model());

        return DB::transaction(function () use ($section, $item): Response {
            $list = HomepageContentList::lock($section);
            $record = $section->model()::query()->with('media')->whereKey($item)->firstOrFail();

            return Inertia::render('admin/cms/homepage/Form', ['section' => $section->value, 'revision' => $list->revision, 'item' => $this->present($record, $section)]);
        });
    }

    public function store(HomepageContentRequest $request, HomepageSection $section, ManageHomepageContent $content): RedirectResponse
    {
        $content->save($request->user(), $section, null, $this->data($request), $this->image($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Element został dodany.']);

        return to_route('admin.cms.homepage.index', ['section' => $section]);
    }

    public function update(HomepageContentRequest $request, HomepageSection $section, int $item, ManageHomepageContent $content): RedirectResponse
    {
        $content->save($request->user(), $section, $item, $this->data($request), $this->image($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zmiany zostały zapisane.']);

        return to_route('admin.cms.homepage.index', ['section' => $section]);
    }

    public function visibility(HomepageContentRequest $request, HomepageSection $section, int $item, ManageHomepageContent $content): RedirectResponse
    {
        $content->visibility($request->user(), $section, $item, $request->integer('revision'), $request->boolean('is_published'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('is_published') ? 'Element jest teraz widoczny na stronie.' : 'Element został ukryty.']);

        return $this->indexRedirect($request, $section);
    }

    public function move(HomepageContentRequest $request, HomepageSection $section, int $item, ManageHomepageContent $content): RedirectResponse
    {
        $content->move($request->user(), $section, $item, $request->integer('revision'), $request->validated('direction'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kolejność została zapisana.']);

        return $this->indexRedirect($request, $section);
    }

    public function destroy(HomepageContentRequest $request, HomepageSection $section, int $item, ManageHomepageContent $content): RedirectResponse
    {
        $content->delete($request->user(), $section, $item, $request->integer('revision'), $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Element został usunięty.']);

        return $this->indexRedirect($request, $section);
    }

    private function indexRedirect(Request $request, HomepageSection $section): RedirectResponse
    {
        $lastPage = max(1, (int) ceil($section->model()::count() / 20));

        return to_route('admin.cms.homepage.index', ['section' => $section, 'page' => min($request->integer('page', 1), $lastPage)]);
    }

    /** @return array<string, mixed> */
    private function present(OfferTile|HeroSlide $item, HomepageSection $section): array
    {
        $image = $item->media;

        return [
            'id' => $item->id, 'title' => $item->title, 'description' => $item->getAttribute($section === HomepageSection::Tiles ? 'short_description' : 'description'),
            'link_url' => $section === HomepageSection::Tiles ? $item->getAttribute('link_url') : null,
            'is_published' => $item->is_published, 'image' => $image === null ? null : [
                'id' => $image->id, 'src' => route('admin.cms.media.show', $image->id), 'alt' => $image->alt_text ?? '',
            ],
        ];
    }

    private function image(HomepageContentRequest $request): ?UploadedFile
    {
        $image = $request->file('image');

        return $image instanceof UploadedFile ? $image : null;
    }

    /** @return array{title:?string,description:?string,link_url:?string,is_published:bool,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string} */
    private function data(HomepageContentRequest $request): array
    {
        return [
            'title' => $request->validated('title'), 'description' => $request->validated('description'), 'link_url' => $request->validated('link_url'),
            'is_published' => $request->boolean('is_published'), 'revision' => $request->integer('revision'),
            'current_media_id' => $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'),
            'remove_image' => $request->boolean('remove_image'), 'alt_text' => $request->validated('alt_text'),
        ];
    }
}
