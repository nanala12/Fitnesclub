<?php

use App\Actions\Cms\ManageHomepageContent;
use App\Enums\HomepageSection;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL test database is required.');
}
[$script, $operation, $actorId, $itemId, $revision, $barrier, $worker] = $argv;
$actor = User::findOrFail((int) $actorId);
touch($barrier.'.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    }
    usleep(10000);
}
try {
    $action = app(ManageHomepageContent::class);
    if ($operation === 'move') {
        $action->move($actor, HomepageSection::Tiles, (int) $itemId, (int) $revision, 'down');
    } else {
        $action->save($actor, HomepageSection::Tiles, null, ['title' => 'Concurrent tile', 'description' => null, 'link_url' => null, 'is_published' => false, 'revision' => (int) $revision, 'current_media_id' => null, 'remove_image' => false, 'alt_text' => null], null);
    }
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
