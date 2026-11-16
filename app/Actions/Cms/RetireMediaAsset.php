<?php

namespace App\Actions\Cms;

use App\Jobs\DeleteCmsMediaFile;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class RetireMediaAsset
{
    public function handle(MediaAsset $asset): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Media retirement requires the content transaction.');
        }
        $deletion = MediaFileDeletion::query()->firstOrCreate(
            ['disk' => $asset->disk, 'path' => $asset->path], ['available_at' => now()],
        );
        $asset->delete();
        DB::afterCommit(fn () => $this->dispatch($deletion->id));
    }

    public function dispatch(int $id): void
    {
        try {
            DeleteCmsMediaFile::dispatch($id)->afterCommit();
        } catch (Throwable) {
            // The durable deletion remains available to the scheduled retry command.
            Log::warning('CMS media cleanup dispatch failed.', ['deletion_id' => $id]);
        }
    }
}
