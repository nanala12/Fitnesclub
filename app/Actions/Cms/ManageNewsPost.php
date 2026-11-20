<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\MediaAsset;
use App\Models\NewsPost;
use App\Models\User;
use App\Support\Cms\PublicationTime;
use App\Support\Cms\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageNewsPost
{
    public function __construct(private ManageCmsMedia $media, private RecordAudit $audit, private RichTextSanitizer $html, private PublicationTime $time) {}

    /** @param array{title:string,excerpt:?string,body:string,is_published:bool,published_at:?string,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string} $data */
    public function save(User $actor, ?int $postId, array $data, ?UploadedFile $image): NewsPost
    {
        Gate::forUser($actor)->authorize('manage', NewsPost::class);
        $body = $this->html->clean($data['body']);
        if ($this->html->text($body) === '') {
            throw ValidationException::withMessages(['body' => 'Wpisz treść aktualności.']);
        }
        $publishedAt = $this->time->parse($data['published_at']);
        if ($data['is_published'] && $publishedAt === null) {
            $publishedAt = now();
        }
        $save = function (?MediaAsset $asset = null) use ($actor, $postId, $data, $body, $publishedAt): NewsPost {
            $post = $postId === null ? new NewsPost : NewsPost::query()->lockForUpdate()->findOrFail($postId);
            if ($postId !== null) {
                $this->checkRevision($post, $data['revision']);
            }
            if ($post->cover_media_id !== $data['current_media_id']) {
                throw ValidationException::withMessages(['image' => 'Zdjęcie zostało zmienione. Wczytaj aktualne dane.']);
            }
            $oldMediaId = $post->cover_media_id;
            $attributes = ['title' => $data['title'], 'excerpt' => $data['excerpt'], 'body' => $body,
                'is_published' => $data['is_published'], 'published_at' => $publishedAt,
                'updated_by_user_id' => $actor->id, 'revision' => $postId === null ? 0 : $post->revision + 1];
            if ($postId === null) {
                $attributes += ['created_by_user_id' => $actor->id, 'slug' => (Str::limit(Str::slug($data['title']), 180, '') ?: 'aktualnosc').'-'.strtolower((string) Str::ulid())];
            }
            if ($asset !== null || $data['remove_image']) {
                $attributes['cover_media_id'] = $asset?->id;
            }
            $post->forceFill($attributes)->save();
            if ($asset === null && ! $data['remove_image'] && $oldMediaId !== null) {
                MediaAsset::query()->whereKey($oldMediaId)->lockForUpdate()->firstOrFail()->forceFill(['alt_text' => $data['alt_text']])->save();
            }
            if ($oldMediaId !== null && ($asset !== null || $data['remove_image'])) {
                $this->audit->handle(AuditAction::MediaRemoved, $oldMediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle($postId === null ? AuditAction::NewsCreated : AuditAction::NewsUpdated, $post->id, $actor, null, null, ['changed_fields' => ['title', 'excerpt', 'body', 'is_published', 'published_at', 'image']]);

            return $post;
        };
        if ($image !== null) {
            $asset = $this->media->upload($actor, $image, $data['alt_text'], $save);

            return NewsPost::where('cover_media_id', $asset->id)->firstOrFail();
        }

        return DB::transaction(fn () => $save());
    }

    public function visibility(User $actor, int $postId, int $revision, bool $visible): void
    {
        Gate::forUser($actor)->authorize('manage', NewsPost::class);
        DB::transaction(function () use ($actor, $postId, $revision, $visible): void {
            $post = NewsPost::query()->lockForUpdate()->findOrFail($postId);
            $this->checkRevision($post, $revision);
            $body = $this->html->clean($post->body);
            if ($visible && $this->html->text($body) === '') {
                throw ValidationException::withMessages(['body' => 'Uzupełnij treść aktualności przed publikacją.']);
            }
            $post->forceFill(['is_published' => $visible, 'published_at' => $visible ? ($post->published_at ?? now()) : $post->published_at,
                'revision' => $post->revision + 1, 'updated_by_user_id' => $actor->id, 'body' => $body])->save();
            $this->audit->handle(AuditAction::NewsUpdated, $post->id, $actor, null, null, ['changed_fields' => ['is_published', 'published_at']]);
        });
    }

    public function delete(User $actor, int $postId, int $revision, ?int $mediaId): void
    {
        Gate::forUser($actor)->authorize('manage', NewsPost::class);
        DB::transaction(function () use ($actor, $postId, $revision, $mediaId): void {
            $post = NewsPost::query()->lockForUpdate()->findOrFail($postId);
            $this->checkRevision($post, $revision);
            if ($post->cover_media_id !== $mediaId) {
                throw ValidationException::withMessages(['image' => 'Zdjęcie zostało zmienione. Wczytaj aktualne dane.']);
            }
            $post->delete();
            if ($mediaId !== null) {
                $this->audit->handle(AuditAction::MediaRemoved, $mediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle(AuditAction::NewsDeleted, $postId, $actor, null, null);
        });
    }

    private function checkRevision(NewsPost $post, int $revision): void
    {
        if ($post->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Ta aktualność została zmieniona. Wczytaj aktualne dane i ponów edycję.']);
        }
    }
}
