<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\GalleryCategory;
use App\Models\GalleryContentState;
use App\Models\GalleryImage;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageGallery
{
    public function __construct(private ManageCmsMedia $media, private RecordAudit $audit) {}

    /** @param array{name:string,short_description:?string,is_published:bool,revision:int} $data */
    public function saveCategory(User $actor, ?int $categoryId, array $data): GalleryCategory
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $categoryId, $data): GalleryCategory {
            $state = GalleryContentState::lock();
            $state->checkRevision($data['revision']);
            $category = $categoryId === null ? new GalleryCategory : GalleryCategory::query()->lockForUpdate()->findOrFail($categoryId);
            $values = ['name' => $data['name'], 'short_description' => $data['short_description'], 'is_published' => $data['is_published']];
            if ($categoryId === null) {
                $values['sort_order'] = (int) (GalleryCategory::max('sort_order') ?? -1) + 1;
            }
            $category->forceFill($values)->save();
            $this->audit->handle($categoryId === null ? AuditAction::GalleryCategoryCreated : AuditAction::GalleryCategoryUpdated, $category->id, $actor, null, null, ['changed_fields' => ['name', 'description', 'is_published']]);
            $state->advance();

            return $category;
        });
    }

    /** @param array{gallery_category_id:int,is_published:bool,revision:int,current_media_id:?int,alt_text:?string} $data */
    public function saveImage(User $actor, ?int $imageId, array $data, ?UploadedFile $file): GalleryImage
    {
        $this->authorize($actor);
        $save = function (?MediaAsset $asset = null) use ($actor, $imageId, $data): GalleryImage {
            $state = GalleryContentState::lock();
            $state->checkRevision($data['revision']);
            GalleryCategory::query()->whereKey($data['gallery_category_id'])->lockForUpdate()->firstOrFail();
            $image = $imageId === null ? new GalleryImage : GalleryImage::query()->whereKey($imageId)->lockForUpdate()->firstOrFail();
            $oldId = $imageId === null ? null : $image->media_asset_id;
            if ($oldId !== $data['current_media_id']) {
                throw ValidationException::withMessages(['image' => 'Zdjęcie zostało już zmienione. Wczytaj aktualne dane.']);
            }
            if ($asset === null && $oldId === null) {
                throw ValidationException::withMessages(['image' => 'Wybierz zdjęcie do galerii.']);
            }
            $values = ['gallery_category_id' => $data['gallery_category_id'], 'is_published' => $data['is_published']];
            if ($imageId === null || $image->gallery_category_id !== $data['gallery_category_id']) {
                $values['sort_order'] = (int) (GalleryImage::where('gallery_category_id', $data['gallery_category_id'])->max('sort_order') ?? -1) + 1;
            }
            if ($asset !== null) {
                $values['media_asset_id'] = $asset->id;
            }
            $image->forceFill($values)->save();
            if ($asset === null) {
                MediaAsset::query()->whereKey($oldId)->lockForUpdate()->firstOrFail()->forceFill(['alt_text' => $data['alt_text']])->save();
            }
            if ($asset !== null && $oldId !== null) {
                $this->audit->handle(AuditAction::MediaRemoved, $oldId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle($imageId === null ? AuditAction::GalleryImageCreated : AuditAction::GalleryImageUpdated, $image->id, $actor, null, null, ['changed_fields' => ['image', 'gallery_category_id', 'is_published']]);
            $state->advance();

            return $image;
        };
        if ($file !== null) {
            $asset = $this->media->upload($actor, $file, $data['alt_text'], $save);

            return GalleryImage::where('media_asset_id', $asset->id)->firstOrFail();
        }

        return DB::transaction(fn () => $save());
    }

    public function visibility(User $actor, bool $category, int $id, int $revision, bool $published): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $category, $id, $revision, $published): void {
            $state = GalleryContentState::lock();
            $state->checkRevision($revision);
            $item = $category ? GalleryCategory::query()->lockForUpdate()->findOrFail($id) : GalleryImage::query()->lockForUpdate()->findOrFail($id);
            $item->forceFill(['is_published' => $published])->save();
            $this->audit->handle($category ? AuditAction::GalleryCategoryUpdated : AuditAction::GalleryImageUpdated, $id, $actor, null, null, ['changed_fields' => ['is_published']]);
            $state->advance();
        });
    }

    public function delete(User $actor, bool $category, int $id, int $revision, ?int $currentMediaId = null): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $category, $id, $revision, $currentMediaId): void {
            $state = GalleryContentState::lock();
            $state->checkRevision($revision);
            if ($category) {
                $item = GalleryCategory::query()->lockForUpdate()->findOrFail($id);
                if (GalleryImage::where('gallery_category_id', $id)->exists()) {
                    throw ValidationException::withMessages(['category' => 'Najpierw przenieś lub usuń zdjęcia z tej kategorii.']);
                }
            } else {
                $item = GalleryImage::query()->lockForUpdate()->findOrFail($id);
                if ($item->media_asset_id !== $currentMediaId) {
                    throw ValidationException::withMessages(['image' => 'Zdjęcie zostało już zmienione. Wczytaj aktualne dane.']);
                }
            }
            $item->delete();
            if (! $category) {
                $this->audit->handle(AuditAction::MediaRemoved, $currentMediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle($category ? AuditAction::GalleryCategoryDeleted : AuditAction::GalleryImageDeleted, $id, $actor, null, null);
            $state->advance();
        });
    }

    public function move(User $actor, bool $category, int $id, int $revision, string $direction): void
    {
        $this->authorize($actor);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Wybierz przesunięcie wyżej albo niżej.']);
        }
        DB::transaction(function () use ($actor, $category, $id, $revision, $direction): void {
            $state = GalleryContentState::lock();
            $state->checkRevision($revision);
            if ($category) {
                $query = GalleryCategory::query();
            } else {
                $image = GalleryImage::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $query = GalleryImage::query()->where('gallery_category_id', $image->gallery_category_id);
            }
            $ids = $query->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
            $position = array_search($id, $ids, true);
            abort_if($position === false, 404);
            $position = (int) $position;
            $next = $position + ($direction === 'up' ? -1 : 1);
            if ($next < 0 || $next >= count($ids)) {
                throw ValidationException::withMessages(['direction' => 'Element jest już na końcu wybranego kierunku.']);
            }
            [$ids[$position],$ids[$next]] = [$ids[$next], $ids[$position]];
            foreach ($ids as $order => $itemId) {
                if ($category) {
                    GalleryCategory::whereKey($itemId)->update(['sort_order' => $order]);
                } else {
                    GalleryImage::whereKey($itemId)->update(['sort_order' => $order]);
                }
            }
            $this->audit->handle($category ? AuditAction::GalleryCategoryReordered : AuditAction::GalleryImageReordered, $id, $actor, null, null, ['changed_fields' => ['sort_order']]);
            $state->advance();
        });
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', GalleryCategory::class);
    }
}
