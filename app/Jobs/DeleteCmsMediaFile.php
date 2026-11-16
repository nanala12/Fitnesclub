<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeleteCmsMediaFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public function __construct(public int $deletionId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $failed = DB::transaction(function (): bool {
            $deletion = MediaFileDeletion::query()->lockForUpdate()->find($this->deletionId);
            if ($deletion === null || $deletion->available_at->isFuture()) {
                return false;
            }
            // An interrupted upload may have completed successfully before recovery.
            if (MediaAsset::where('disk', $deletion->disk)->where('path', $deletion->path)->exists()) {
                $deletion->delete();

                return false;
            }
            try {
                if ($deletion->disk !== 'local' || ! preg_match('~^cms/[a-zA-Z0-9_/.-]+$~D', $deletion->path) || str_contains($deletion->path, '..')) {
                    throw new RuntimeException('Unsafe CMS cleanup path.');
                }
                $disk = Storage::disk('local');
                if ($disk->exists($deletion->path) && ! $disk->delete($deletion->path)) {
                    throw new RuntimeException('CMS file deletion failed.');
                }
                $deletion->delete();

                return false;
            } catch (Throwable) {
                $deletion->forceFill(['attempts' => $deletion->attempts + 1, 'available_at' => now()->addMinutes(5)])->save();
                Log::warning('CMS media cleanup failed; retry pending.', ['deletion_id' => $deletion->id, 'attempt' => $deletion->attempts]);

                return true;
            }
        });
        if ($failed) {
            throw new RuntimeException('CMS media cleanup failed; durable retry retained.');
        }
    }
}
