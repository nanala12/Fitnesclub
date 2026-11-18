<?php

use App\Actions\Cms\ManageRegulation;
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
    $regulations = app(ManageRegulation::class);
    if ($mode === 'create') {
        $regulations->save($actor, null, ['body' => '<p>Concurrent terms</p>', 'current_media_id' => null, 'remove_image' => false, 'alt_text' => null, 'revision' => (int) $revision], null);
    } else {
        $regulations->publish($actor, (int) $itemId, (int) $revision);
    }
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
