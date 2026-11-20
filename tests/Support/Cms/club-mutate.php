<?php

use App\Actions\Cms\ManageClubContent;
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
    $club = app(ManageClubContent::class);
    if ($mode === 'contact') {
        $hours = array_map(fn (int $day): array => ['weekday' => $day, 'status' => 'unknown'], range(1, 7));
        $club->saveContact($actor, (int) $revision, ['club_name' => 'Concurrent club'], $hours);
    } else {
        $club->saveType($actor, null, (int) $revision, ['name' => 'Concurrent class', 'description' => null, 'is_published' => false]);
    }
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
