<?php

namespace App\Http\Controllers\PublicSite;

use App\Actions\PublicSite\PublicSiteContent;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __invoke(MediaAsset $mediaAsset, PublicSiteContent $content): StreamedResponse
    {
        abort_unless($content->safeAsset($mediaAsset) && $content->publiclyReferenced($mediaAsset), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($mediaAsset->path) && $disk->mimeType($mediaAsset->path) === $mediaAsset->mime_type, 404);

        return $disk->response($mediaAsset->path, null, ['Content-Type' => $mediaAsset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
