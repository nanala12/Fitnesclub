<?php

namespace App\Console\Commands;

use App\Actions\Cms\RetireMediaAsset;
use App\Models\MediaFileDeletion;
use Illuminate\Console\Command;

class RetryCmsMediaCleanup extends Command
{
    protected $signature = 'cms:retry-media-cleanup';

    protected $description = 'Queue due CMS file deletions, including interrupted uploads';

    public function handle(RetireMediaAsset $retire): int
    {
        $ids = MediaFileDeletion::query()->where('available_at', '<=', now())->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $retire->dispatch($id);
        }
        $this->info('Zlecono ponowienie usuwania plików: '.$ids->count());

        return self::SUCCESS;
    }
}
