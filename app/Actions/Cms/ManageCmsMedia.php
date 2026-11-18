<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\HomepageSection;
use App\Enums\MediaPlacement;
use App\Models\GalleryContentState;
use App\Models\HomepageContentList;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\NewsPost;
use App\Models\RegulationContentState;
use App\Models\User;
use App\Rules\CmsImage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class ManageCmsMedia
{
    public function __construct(private RecordAudit $audit, private RetireMediaAsset $retire) {}

    /**
     * The callback saves a new or existing CMS owner and its other fields in the same transaction.
     * Upload recovery is staged outside it, so this must be the outer business boundary.
     *
     * @param  Closure(MediaAsset): Model  $saveOwner
     */
    public function upload(User $actor, UploadedFile $image, ?string $altText, Closure $saveOwner): MediaAsset
    {
        Gate::forUser($actor)->authorize('manage', MediaAsset::class);
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('CMS upload must own the outer transaction.');
        }
        Validator::make(['image' => $image, 'alt_text' => $altText], ['image' => ['required', new CmsImage], 'alt_text' => ['nullable', 'string', 'max:255']])->validate();
        $path = 'cms/'.Str::uuid().'.'.(CmsImage::EXTENSIONS[CmsImage::mimeType($image) ?? ''] ?? throw new LogicException('Image validation must run before storage.'));
        // If the process crashes after writing the file, the scheduler can recover it.
        $staged = MediaFileDeletion::forceCreate(['disk' => 'local', 'path' => $path, 'available_at' => now()->addHour()]);
        try {
            if (Storage::disk('local')->putFileAs('cms', $image, basename($path)) === false) {
                throw ValidationException::withMessages(['image' => 'Nie udało się zapisać zdjęcia. Spróbuj ponownie.']);
            }

            return DB::transaction(function () use ($actor, $image, $altText, $saveOwner, $path, $staged): MediaAsset {
                MediaFileDeletion::query()->whereKey($staged->id)->lockForUpdate()->firstOrFail();
                $asset = MediaAsset::forceCreate(['disk' => 'local', 'path' => $path, 'mime_type' => CmsImage::mimeType($image), 'size_bytes' => $image->getSize(), 'alt_text' => $altText, 'uploaded_by_user_id' => $actor->id]);
                $owner = $saveOwner($asset);
                $placement = MediaPlacement::forModel($owner);
                $asset->refresh();
                if (! $owner->exists || $owner->getAttribute($placement->column()) !== $asset->id || $asset->owner_type !== $placement->value || $asset->owner_id !== $owner->getKey()) {
                    throw new LogicException('The uploaded image must be attached to exactly one persisted CMS owner.');
                }
                $this->audit->handle(AuditAction::MediaUploaded, $asset->id, $actor, null, null, ['changed_fields' => ['image']]);
                $staged->delete();

                return $asset;
            });
        } catch (Throwable $error) {
            $staged->forceFill(['available_at' => now()])->save();
            $this->retire->dispatch($staged->id);
            if ($error instanceof ValidationException || $error instanceof LogicException || $error instanceof ModelNotFoundException) {
                throw $error;
            }
            Log::error('CMS media upload failed.', ['deletion_id' => $staged->id, 'exception_type' => $error::class]);
            // Do not expose file paths or client filenames through exception details.
            throw ValidationException::withMessages(['image' => 'Nie udało się zapisać zdjęcia. Spróbuj ponownie.']);
        }
    }

    public function replace(User $actor, MediaPlacement $placement, int $ownerId, ?int $currentMediaId, UploadedFile $image, ?string $altText): MediaAsset
    {
        return $this->upload($actor, $image, $altText, function (MediaAsset $asset) use ($actor, $placement, $ownerId, $currentMediaId): Model {
            $section = HomepageSection::forPlacement($placement);
            $list = $section === null ? null : HomepageContentList::lock($section);
            $gallery = $placement === MediaPlacement::Gallery ? GalleryContentState::lock() : null;
            $regulation = $placement === MediaPlacement::Regulation ? RegulationContentState::lock() : null;
            $owner = $placement->model()::query()->lockForUpdate()->findOrFail($ownerId);
            $this->checkCurrent($owner, $placement, $currentMediaId);
            $owner->forceFill([$placement->column() => $asset->id, ...($owner instanceof NewsPost ? ['revision' => $owner->revision + 1, 'updated_by_user_id' => $actor->id] : [])])->save();
            if ($currentMediaId !== null) {
                $this->audit->handle(AuditAction::MediaRemoved, $currentMediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }

            $list?->advance();
            $gallery?->advance();
            $regulation?->advance();

            return $owner;
        });
    }

    public function remove(User $actor, MediaPlacement $placement, int $ownerId, int $currentMediaId): void
    {
        Gate::forUser($actor)->authorize('manage', MediaAsset::class);
        DB::transaction(function () use ($actor, $placement, $ownerId, $currentMediaId): void {
            $section = HomepageSection::forPlacement($placement);
            $list = $section === null ? null : HomepageContentList::lock($section);
            $gallery = $placement === MediaPlacement::Gallery ? GalleryContentState::lock() : null;
            $regulation = $placement === MediaPlacement::Regulation ? RegulationContentState::lock() : null;
            $owner = $placement->model()::query()->lockForUpdate()->findOrFail($ownerId);
            $this->checkCurrent($owner, $placement, $currentMediaId);
            if ($placement->requiresImage()) {
                $owner->delete();
            } else {
                $owner->forceFill([$placement->column() => null, ...($owner instanceof NewsPost ? ['revision' => $owner->revision + 1, 'updated_by_user_id' => $actor->id] : [])])->save();
            }
            $this->audit->handle(AuditAction::MediaRemoved, $currentMediaId, $actor, null, null, ['changed_fields' => ['image']]);
            $list?->advance();
            $gallery?->advance();
            $regulation?->advance();
        });
    }

    private function checkCurrent(Model $owner, MediaPlacement $placement, ?int $currentMediaId): void
    {
        if ($owner->getAttribute($placement->column()) !== $currentMediaId) {
            throw ValidationException::withMessages(['image' => 'Zdjęcie zostało już zmienione. Odśwież stronę przed kolejną zmianą.']);
        }
    }
}
