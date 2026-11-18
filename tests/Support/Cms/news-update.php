<?php

use App\Actions\Cms\ManageNewsPost;
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
[$script, $actorId, $postId, $revision, $barrier, $worker] = $argv;
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
    app(ManageNewsPost::class)->save($actor, (int) $postId, ['title' => 'Concurrent edit '.$worker, 'excerpt' => null, 'body' => '<p>Concurrent body</p>',
        'is_published' => false, 'published_at' => null, 'revision' => (int) $revision, 'current_media_id' => null, 'remove_image' => false, 'alt_text' => null], null);
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
