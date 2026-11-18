<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\MediaAsset;
use App\Models\RegulationContentState;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\Cms\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageRegulation
{
    public function __construct(private ManageCmsMedia $media, private RecordAudit $audit, private RichTextSanitizer $html) {}

    /** @param array{body:string,revision:int,current_media_id:?int,remove_image:bool,alt_text:?string} $data */
    public function save(User $actor, ?int $id, array $data, ?UploadedFile $file): RegulationVersion
    {
        Gate::forUser($actor)->authorize('manage', RegulationVersion::class);
        $body = $this->html->clean($data['body']);
        $this->requireBody($body);
        $save = function (?MediaAsset $asset = null) use ($actor, $id, $data, $body): RegulationVersion {
            $state = RegulationContentState::lock();
            $state->checkRevision($data['revision']);
            $version = $id === null ? new RegulationVersion : RegulationVersion::query()->lockForUpdate()->findOrFail($id);
            $this->requireDraft($version);
            $oldId = $id === null ? null : $version->media_asset_id;
            if ($oldId !== $data['current_media_id']) {
                throw ValidationException::withMessages(['image' => 'Zdjęcie zostało zmienione. Wczytaj aktualne dane.']);
            }
            $values = ['body' => $body];
            if ($id === null) {
                $values += ['version_number' => (int) RegulationVersion::max('version_number') + 1, 'created_by_user_id' => $actor->id];
            }
            if ($asset !== null || $data['remove_image']) {
                $values['media_asset_id'] = $asset?->id;
            }
            $version->forceFill($values)->save();
            if ($asset === null && ! $data['remove_image'] && $oldId !== null) {
                MediaAsset::query()->whereKey($oldId)->lockForUpdate()->firstOrFail()->forceFill(['alt_text' => $data['alt_text']])->save();
            }
            if ($oldId !== null && ($asset !== null || $data['remove_image'])) {
                $this->audit->handle(AuditAction::MediaRemoved, $oldId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle($id === null ? AuditAction::RegulationCreated : AuditAction::RegulationUpdated, $version->id, $actor, null, null, ['changed_fields' => ['body', 'image']]);
            $state->advance();

            return $version;
        };
        if ($file !== null) {
            $asset = $this->media->upload($actor, $file, $data['alt_text'], $save);

            return RegulationVersion::where('media_asset_id', $asset->id)->firstOrFail();
        }

        return DB::transaction(fn () => $save());
    }

    public function publish(User $actor, int $id, int $revision): void
    {
        Gate::forUser($actor)->authorize('manage', RegulationVersion::class);
        DB::transaction(function () use ($actor, $id, $revision): void {
            $state = RegulationContentState::lock();
            $state->checkRevision($revision);
            $version = RegulationVersion::query()->lockForUpdate()->findOrFail($id);
            $this->requireDraft($version);
            $currentNumber = (int) RegulationVersion::whereNotNull('published_at')->max('version_number');
            if ($version->version_number <= $currentNumber) {
                throw ValidationException::withMessages(['revision' => 'Opublikowano już nowszą wersję. Utwórz nowy szkic na podstawie aktualnego regulaminu.']);
            }
            $body = $this->html->clean($version->body);
            $this->requireBody($body);
            $version->forceFill(['body' => $body, 'published_at' => now()])->save();
            $this->audit->handle(AuditAction::RegulationPublished, $id, $actor, null, null, ['changed_fields' => ['published_at']]);
            $state->advance();
        });
    }

    public function delete(User $actor, int $id, int $revision): void
    {
        Gate::forUser($actor)->authorize('manage', RegulationVersion::class);
        DB::transaction(function () use ($actor, $id, $revision): void {
            $state = RegulationContentState::lock();
            $state->checkRevision($revision);
            $version = RegulationVersion::query()->lockForUpdate()->findOrFail($id);
            $this->requireDraft($version);
            $mediaId = $version->media_asset_id;
            $version->delete();
            if ($mediaId !== null) {
                $this->audit->handle(AuditAction::MediaRemoved, $mediaId, $actor, null, null, ['changed_fields' => ['image']]);
            }
            $this->audit->handle(AuditAction::RegulationDeleted, $id, $actor, null, null);
            $state->advance();
        });
    }

    private function requireDraft(RegulationVersion $version): void
    {
        if ($version->published_at !== null) {
            throw ValidationException::withMessages(['body' => 'Opublikowana wersja regulaminu jest niezmienna. Utwórz nową wersję.']);
        }
    }

    private function requireBody(string $body): void
    {
        if ($this->html->text($body) === '') {
            throw ValidationException::withMessages(['body' => 'Wpisz treść regulaminu.']);
        }
    }
}
