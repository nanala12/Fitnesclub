<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $url = $app['config']->get("database.connections.{$connection}.url");

        if (! $app->environment('testing') || $url || ! (
            ($connection === 'sqlite' && $database === ':memory:')
            || ($connection === 'mysql' && $database === 'activefit_testing')
        )) {
            throw new RuntimeException('Tests require an isolated testing database. Clear the configuration cache and use phpunit.xml or phpunit.mysql.xml.');
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
