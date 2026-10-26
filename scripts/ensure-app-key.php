<?php

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$environment = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();

if (empty($environment['APP_KEY'])) {
    $process = new Process([PHP_BINARY, 'artisan', 'key:generate'], $root);
    $process->run(fn ($type, $output) => print $output);

    exit($process->getExitCode());
}
