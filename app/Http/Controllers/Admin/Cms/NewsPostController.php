<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageNewsPost;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\NewsPostRequest;
use App\Models\NewsPost;
use App\Support\Cms\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NewsPostController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', NewsPost::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('admin/cms/news/Index', ['posts' => NewsPost::query()->select(['id', 'title', 'excerpt', 'is_published', 'revision', 'published_at', 'cover_media_id', 'slug', 'created_at'])->with('media')->orderByDesc('created_at')->orderByDesc('id')->paginate(20)
            ->through(fn (NewsPost $post): array => $this->present($post))]);
    }

    public function create(): Response
    {
        Gate::authorize('manage', NewsPost::class);

        return Inertia::render('admin/cms/news/Form', ['post' => null]);
    }

    public function edit(NewsPost $newsPost): Response
    {
        Gate::authorize('manage', NewsPost::class);
        $newsPost->load('media');

        return Inertia::render('admin/cms/news/Form', ['post' => $this->present($newsPost, true)]);
    }

    public function store(NewsPostRequest $request, ManageNewsPost $news): RedirectResponse
    {
        $news->save($request->user(), null, $this->data($request), $this->image($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aktualność została dodana.']);

        return to_route('admin.cms.news.index');
    }

    public function update(NewsPostRequest $request, NewsPost $newsPost, ManageNewsPost $news): RedirectResponse
    {
        $news->save($request->user(), $newsPost->id, $this->data($request), $this->image($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zmiany zostały zapisane.']);

        return to_route('admin.cms.news.index');
    }

    public function visibility(NewsPostRequest $request, NewsPost $newsPost, ManageNewsPost $news): RedirectResponse
    {
        $news->visibility($request->user(), $newsPost->id, $request->integer('revision'), $request->boolean('is_published'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('is_published') ? 'Publikacja aktualności została włączona zgodnie z wybraną datą.' : 'Aktualność została ukryta.']);

        return $this->indexRedirect($request);
    }

    public function destroy(NewsPostRequest $request, NewsPost $newsPost, ManageNewsPost $news): RedirectResponse
    {
        $news->delete($request->user(), $newsPost->id, $request->integer('revision'), $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aktualność została usunięta.']);

        return $this->indexRedirect($request);
    }

    private function indexRedirect(Request $request): RedirectResponse
    {
        return to_route('admin.cms.news.index', ['page' => min($request->integer('page', 1), max(1, (int) ceil(NewsPost::count() / 20)))]);
    }

    /** @return array<string, mixed> */
    private function present(NewsPost $post, bool $includeBody = false): array
    {
        $scheduled = $post->published_at?->isFuture() ?? false;
        $visible = $post->is_published && $post->published_at !== null && ! $scheduled;

        return [...($includeBody ? ['body' => app(RichTextSanitizer::class)->clean($post->body)] : []), 'id' => $post->id, 'title' => $post->title, 'excerpt' => $post->excerpt, 'is_published' => $post->is_published,
            'revision' => $post->revision, 'publishedAt' => $post->published_at?->toISOString(),
            'publicationLocal' => $post->published_at?->setTimezone(config('app.business_timezone'))->format('Y-m-d\TH:i'),
            'status' => $visible ? 'published' : ($post->is_published && $scheduled ? 'scheduled' : 'draft'),
            'publicUrl' => $visible ? route('public.news.show', $post->slug) : null,
            'image' => $post->media === null ? null : ['id' => $post->media->id, 'src' => route('admin.cms.media.show', $post->media->id), 'alt' => $post->media->alt_text ?? '']];
    }

    private function image(NewsPostRequest $request): ?UploadedFile
    {
        $file = $request->file('image');

        return $file instanceof UploadedFile ? $file : null;
    }

    /** @return array{title:string,excerpt:?string,body:string,is_published:bool,published_at:?string,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string} */
    private function data(NewsPostRequest $request): array
    {
        return ['title' => $request->validated('title'), 'excerpt' => $request->validated('excerpt'), 'body' => $request->validated('body'),
            'is_published' => $request->boolean('is_published'), 'published_at' => $request->validated('published_at'), 'revision' => $request->integer('revision'),
            'current_media_id' => $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'),
            'remove_image' => $request->boolean('remove_image'), 'alt_text' => $request->validated('alt_text')];
    }
}
