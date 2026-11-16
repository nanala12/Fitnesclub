<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageCmsMedia;
use App\Actions\PublicSite\PublicSiteContent;
use App\Enums\MediaPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\MediaRequest;
use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function store(MediaRequest $request, MediaPlacement $placement, int $owner, ManageCmsMedia $media): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('image');
        $currentId = $request->validated('current_media_id');
        $media->replace($request->user(), $placement, $owner, $currentId === null ? null : (int) $currentId, $file, $request->validated('alt_text'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie zostało zapisane.']);

        return back();
    }

    public function destroy(MediaRequest $request, MediaPlacement $placement, int $owner, ManageCmsMedia $media): RedirectResponse
    {
        $media->remove($request->user(), $placement, $owner, (int) $request->validated('current_media_id'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zdjęcie zostało usunięte.']);

        return back();
    }

    public function show(MediaAsset $mediaAsset, PublicSiteContent $content): StreamedResponse
    {
        Gate::authorize('view', $mediaAsset);
        abort_unless($content->safeAsset($mediaAsset), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($mediaAsset->path) && $disk->mimeType($mediaAsset->path) === $mediaAsset->mime_type, 404);

        return $disk->response($mediaAsset->path, null, ['Content-Type' => $mediaAsset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], 'inline');
    }
}
