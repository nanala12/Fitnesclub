<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Mail\QueueProbeMail;

uses(DatabaseMigrations::class);

test('timestamps are stored in UTC and business dates use Warsaw including DST', function (string $instant, string $local) {
    $this->travelTo(Carbon::parse($instant), function () use ($local) {
        expect(date_default_timezone_get())->toBe('UTC')
            ->and(now()->timezoneName)->toBe('UTC')
            ->and(now()->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i P'))->toBe($local);

        $user = User::factory()->create();

        expect(DB::table('users')->where('id', $user->id)->value('created_at'))
            ->toBe(now()->format('Y-m-d H:i:s'));

        if (DB::getDriverName() === 'mysql') {
            expect(DB::scalar('SELECT @@session.time_zone'))->toBe('+00:00');
        }
    });
})->with([
    'Warsaw next day' => ['2026-06-30T22:30:00Z', '2026-07-01 00:30 +02:00'],
    'before spring DST' => ['2026-03-29T00:30:00Z', '2026-03-29 01:30 +01:00'],
    'after spring DST' => ['2026-03-29T01:30:00Z', '2026-03-29 03:30 +02:00'],
    'before autumn DST' => ['2026-10-25T00:30:00Z', '2026-10-25 02:30 +02:00'],
    'after autumn DST' => ['2026-10-25T01:30:00Z', '2026-10-25 02:30 +01:00'],
]);

test('default seeding does not create demo accounts', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 0);
});

test('demo seeding is refused outside the local environment', function (string $environment) {
    $this->app->instance('env', $environment);

    try {
        expect(fn () => app(LocalDatabaseSeeder::class)->run())->toThrow(LogicException::class);
        $this->assertDatabaseCount('users', 0);
    } finally {
        $this->app->instance('env', 'testing');
    }
})->with(['testing', 'staging', 'production']);

test('local demo seeding is repeatable and does not overwrite credentials', function () {
    $this->app->instance('env', 'local');

    try {
        $this->seed(LocalDatabaseSeeder::class);
        $user = User::sole();

        expect(Hash::check('password', $user->password))->toBeFalse();

        $user->update(['password' => 'changed-local-password']);
        $this->seed(LocalDatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        expect(Hash::check('changed-local-password', $user->fresh()->password))->toBeTrue();
    } finally {
        $this->app->instance('env', 'testing');
    }
});

test('queued mail is delivered by the worker only after commit', function () {
    $transport = Mail::mailer('array')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    DB::transaction(function () use ($transport) {
        Mail::mailer('array')->to('queue@example.test')->queue(
            (new QueueProbeMail)->onConnection('database'),
        );

        $this->assertDatabaseCount('jobs', 0);
        expect($transport->messages())->toHaveCount(0);
    });

    $this->assertDatabaseCount('jobs', 1);
    expect($transport->messages())->toHaveCount(0);

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--tries' => 1,
    ])->assertSuccessful();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect($transport->messages())->toHaveCount(1);
});

test('rolling back a transaction discards its queued mail', function () {
    expect(function () {
        DB::transaction(function () {
            Mail::mailer('array')->to('queue@example.test')->queue(
                (new QueueProbeMail)->onConnection('database'),
            );

            throw new RuntimeException('Rollback probe.');
        });
    })->toThrow(RuntimeException::class, 'Rollback probe.');

    $this->assertDatabaseCount('jobs', 0);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});
