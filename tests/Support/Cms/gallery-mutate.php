<?php

use App\Actions\Cms\ManageGallery;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script,$mode,$actorId,$itemId,$revision,$barrier,$worker] = $argv;
$actor = User::findOrFail((int) $actorId);
touch($barrier.'.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    } usleep(10000);
}
try {
    $gallery = app(ManageGallery::class);
    if ($mode === 'create') {
        $gallery->saveCategory($actor, null, ['name' => 'Concurrent category', 'short_description' => null, 'is_published' => false, 'revision' => (int) $revision]);
    } else {
        $gallery->move($actor, false, (int) $itemId, (int) $revision, 'up');
    }
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
