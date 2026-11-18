<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageRegulation;
use App\Actions\PublicSite\PublicSiteContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\RegulationRequest;
use App\Models\RegulationContentState;
use App\Models\RegulationVersion;
use App\Support\Cms\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RegulationController extends Controller
{
    public function index(Request $request, PublicSiteContent $content): Response
    {
        Gate::authorize('manage', RegulationVersion::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return DB::transaction(function () use ($content): Response {
            $state = RegulationContentState::lock();
            $currentId = $content->publishedRegulations()->value('id');
            $maxPublished = (int) RegulationVersion::whereNotNull('published_at')->max('version_number');
            $versions = RegulationVersion::query()->select(['id', 'version_number', 'published_at', 'created_at'])->orderByDesc('version_number')->paginate(20)
                ->through(fn (RegulationVersion $version): array => ['id' => $version->id, 'versionNumber' => $version->version_number, 'publishedAt' => $version->published_at?->toISOString(),
                    'status' => $version->published_at === null ? 'draft' : ($version->id === $currentId ? 'current' : 'history'), 'canPublish' => $version->published_at === null && $version->version_number > $maxPublished]);

            return Inertia::render('admin/cms/regulations/Index', ['versions' => $versions, 'revision' => $state->revision]);
        });
    }

    public function create(PublicSiteContent $content): Response
    {
        Gate::authorize('manage', RegulationVersion::class);

        return DB::transaction(function () use ($content): Response {
            $state = RegulationContentState::lock();
            $current = $content->publishedRegulations()->first();

            return Inertia::render('admin/cms/regulations/Form', ['version' => null, 'revision' => $state->revision, 'initialBody' => $current === null ? '' : app(RichTextSanitizer::class)->clean($current->body)]);
        });
    }

    public function edit(RegulationVersion $regulationVersion): Response
    {
        Gate::authorize('manage', RegulationVersion::class);

        return DB::transaction(function () use ($regulationVersion): Response {
            $state = RegulationContentState::lock();
            $regulationVersion->refresh()->load('media');

            return Inertia::render('admin/cms/regulations/Form', ['version' => ['id' => $regulationVersion->id, 'versionNumber' => $regulationVersion->version_number,
                'body' => app(RichTextSanitizer::class)->clean($regulationVersion->body), 'publishedAt' => $regulationVersion->published_at?->toISOString(),
                'image' => $regulationVersion->media === null ? null : ['id' => $regulationVersion->media->id, 'src' => route('admin.cms.media.show', $regulationVersion->media->id), 'alt' => $regulationVersion->media->alt_text ?? '']],
                'revision' => $state->revision, 'initialBody' => '']);
        });
    }

    public function store(RegulationRequest $request, ManageRegulation $regulations): RedirectResponse
    {
        $regulations->save($request->user(), null, $this->data($request), $this->image($request));

        return $this->redirect($request, 'Szkic regulaminu został dodany.');
    }

    public function update(RegulationRequest $request, RegulationVersion $regulationVersion, ManageRegulation $regulations): RedirectResponse
    {
        $regulations->save($request->user(), $regulationVersion->id, $this->data($request), $this->image($request));

        return $this->redirect($request, 'Szkic regulaminu został zapisany.');
    }

    public function publish(RegulationRequest $request, RegulationVersion $regulationVersion, ManageRegulation $regulations): RedirectResponse
    {
        $regulations->publish($request->user(), $regulationVersion->id, $request->integer('revision'));

        return $this->redirect($request, 'Nowa wersja regulaminu została opublikowana.');
    }

    public function destroy(RegulationRequest $request, RegulationVersion $regulationVersion, ManageRegulation $regulations): RedirectResponse
    {
        $regulations->delete($request->user(), $regulationVersion->id, $request->integer('revision'));

        return $this->redirect($request, 'Szkic regulaminu został usunięty.');
    }

    private function redirect(Request $request, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.cms.regulations.index', ['page' => min($request->integer('page', 1), max(1, (int) ceil(RegulationVersion::count() / 20)))]);
    }

    /** @return array{body:string,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string} */
    private function data(RegulationRequest $request): array
    {
        return ['body' => $request->validated('body'), 'revision' => $request->integer('revision'),
            'current_media_id' => $request->validated('current_media_id') === null ? null : $request->integer('current_media_id'),
            'remove_image' => $request->boolean('remove_image'), 'alt_text' => $request->validated('alt_text')];
    }

    private function image(RegulationRequest $request): ?UploadedFile
    {
        $image = $request->file('image');

        return $image instanceof UploadedFile ? $image : null;
    }
}
