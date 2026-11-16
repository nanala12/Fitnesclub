<?php

use App\Models\OfferTile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('Concurrency probe requires the isolated testing database.');
}
[$script, $ownerId, $mediaId, $barrier] = $argv;
$owner = OfferTile::findOrFail((int) $ownerId);
touch($barrier.'.'.$ownerId);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency test barrier timed out.');
    }
    usleep(10000);
}
try {
    $owner->forceFill(['media_asset_id' => (int) $mediaId])->save();
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
