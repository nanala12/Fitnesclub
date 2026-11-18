<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\HomepageSection;
use App\Models\HeroSlide;
use App\Models\HomepageContentList;
use App\Models\MediaAsset;
use App\Models\OfferTile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageHomepageContent
{
    public function __construct(private ManageCmsMedia $media, private RecordAudit $audit) {}

    /**
     * @param  array{title:?string,description:?string,link_url:?string,is_published:bool,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string}  $data
     */
    public function save(User $actor, HomepageSection $section, ?int $itemId, array $data, ?UploadedFile $image): OfferTile|HeroSlide
    {
        Gate::forUser($actor)->authorize('manage', $section->model());
        $save = function (?MediaAsset $asset = null) use ($actor, $section, $itemId, $data): OfferTile|HeroSlide {
            $list = HomepageContentList::lock($section);
            $list->checkRevision($data['revision']);
            $item = $itemId === null ? new ($section->model()) : $section->model()::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $previousMedia = $item->getAttribute('media_asset_id');
            $this->checkImage($previousMedia, $data['current_media_id']);
            if ($section === HomepageSection::Slides && (($asset === null && $previousMedia === null) || $data['remove_image'])) {
                throw ValidationException::withMessages(['image' => 'Slajd musi mieć zdjęcie.']);
            }
            $attributes = ['title' => $data['title'], 'is_published' => $data['is_published']];
            if ($section === HomepageSection::Tiles) {
                $attributes += ['short_description' => $data['description'], 'link_url' => $data['link_url']];
            } else {
                $attributes['description'] = $data['description'];
            }
            if ($itemId === null) {
                $attributes['sort_order'] = (int) ($section->model()::max('sort_order') ?? -1) + 1;
            }
            if ($asset !== null || $data['remove_image']) {
                $attributes['media_asset_id'] = $asset?->id;
            }
            $item->forceFill($attributes)->save();
            if ($asset === null && ! $data['remove_image'] && $previousMedia !== null) {
                MediaAsset::query()->whereKey($previousMedia)->lockForUpdate()->firstOrFail()->forceFill(['alt_text' => $data['alt_text']])->save();
            }
            if ($previousMedia !== null && ($asset !== null || $data['remove_image'])) {
                $this->audit->handle(AuditAction::MediaRemoved, $previousMedia, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $fields = ['title', 'description', 'is_published'];
            if ($section === HomepageSection::Tiles) {
                $fields[] = 'link_url';
            }
            if ($asset !== null || $previousMedia !== null) {
                $fields[] = 'image';
            }
            $this->audit->handle($section->audit($itemId === null ? 'created' : 'updated'), $item->id, $actor, null, null, ['changed_fields' => $fields]);
            $list->advance();

            return $item;
        };
        if ($image !== null) {
            $asset = $this->media->upload($actor, $image, $data['alt_text'], $save);

            return $section->model()::query()->where('media_asset_id', $asset->id)->firstOrFail();
        }

        return DB::transaction(fn () => $save());
    }

    public function visibility(User $actor, HomepageSection $section, int $itemId, int $revision, bool $published): void
    {
        Gate::forUser($actor)->authorize('manage', $section->model());
        DB::transaction(function () use ($actor, $section, $itemId, $revision, $published): void {
            $list = HomepageContentList::lock($section);
            $list->checkRevision($revision);
            $item = $section->model()::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if ($published && $section === HomepageSection::Slides && $item->media_asset_id === null) {
                throw ValidationException::withMessages(['image' => 'Dodaj zdjęcie przed opublikowaniem slajdu.']);
            }
            $item->forceFill(['is_published' => $published])->save();
            $this->audit->handle($section->audit('updated'), $item->id, $actor, null, null, ['changed_fields' => ['is_published']]);
            $list->advance();
        });
    }

    public function delete(User $actor, HomepageSection $section, int $itemId, int $revision, ?int $currentMediaId): void
    {
        Gate::forUser($actor)->authorize('manage', $section->model());
        DB::transaction(function () use ($actor, $section, $itemId, $revision, $currentMediaId): void {
            $list = HomepageContentList::lock($section);
            $list->checkRevision($revision);
            $item = $section->model()::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $this->checkImage($item->media_asset_id, $currentMediaId);
            $item->delete();
            if ($currentMediaId !== null) {
                $this->audit->handle(AuditAction::MediaRemoved, $currentMediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle($section->audit('deleted'), $itemId, $actor, null, null);
            $list->advance();
        });
    }

    public function move(User $actor, HomepageSection $section, int $itemId, int $revision, string $direction): void
    {
        Gate::forUser($actor)->authorize('manage', $section->model());
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Wybierz przesunięcie wyżej albo niżej.']);
        }
        DB::transaction(function () use ($actor, $section, $itemId, $revision, $direction): void {
            $list = HomepageContentList::lock($section);
            $list->checkRevision($revision);
            $ids = $section->model()::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
            $position = array_search($itemId, $ids, true);
            abort_if($position === false, 404);
            $position = (int) $position;
            $target = $position + ($direction === 'up' ? -1 : 1);
            if ($target < 0 || $target >= count($ids)) {
                throw ValidationException::withMessages(['direction' => 'Ten element jest już na końcu wybranego kierunku.']);
            }
            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
            // The complete canonical order is server-derived, never supplied as arbitrary IDs.
            foreach ($ids as $order => $id) {
                $section->model()::query()->whereKey($id)->update(['sort_order' => $order]);
            }
            $this->audit->handle($section->audit('reordered'), $itemId, $actor, null, null, ['changed_fields' => ['sort_order']]);
            $list->advance();
        });
    }

    private function checkImage(?int $actualId, ?int $expectedId): void
    {
        if ($actualId !== $expectedId) {
            throw ValidationException::withMessages(['image' => 'Zdjęcie zostało już zmienione. Wczytaj aktualne dane przed zapisem.']);
        }
    }
}
