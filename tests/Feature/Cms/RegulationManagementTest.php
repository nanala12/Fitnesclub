<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageRegulation;
use App\Enums\MediaPlacement;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\RegulationContentState;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
});
function regulationRevision(): int
{
    return RegulationContentState::findOrFail(1)->revision;
}
function regulationData(array $overrides = []): array
{
    return [...['body' => '<h2>Zasady</h2><p>Treść regulaminu.</p>', 'revision' => regulationRevision(), 'current_media_id' => null, 'remove_image' => false, 'alt_text' => 'Zdjęcie klubu'], ...$overrides];
}
function regulationUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('terms.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function regulationDraft(User $actor, ?UploadedFile $file = null): RegulationVersion
{
    return app(ManageRegulation::class)->save($actor, null, regulationData(), $file);
}

test('admin reads empty regulation index and creates hidden sanitized draft returning to index', function () {
    $this->actingAs($this->admin)->get(route('admin.cms.regulations.index'))->assertInertia(fn (Assert $page) => $page->where('versions.total', 0)->where('revision', 0));
    $this->get(route('admin.cms.regulations.create'))->assertInertia(fn (Assert $page) => $page->where('initialBody', '')->where('version', null));
    $this->post(route('admin.cms.regulations.store'), regulationData(['body' => '<p onclick="bad()">Zasady <b>klubu</b></p><script>bad()</script>', 'version_number' => 99, 'published_at' => now()->toISOString(), 'created_by_user_id' => 99]))->assertSessionHasNoErrors()->assertRedirect(route('admin.cms.regulations.index', ['page' => 1]));
    $version = RegulationVersion::sole();
    expect($version->version_number)->toBe(1)->and($version->body)->toBe('<p>Zasady <strong>klubu</strong></p>')->and($version->published_at)->toBeNull()->and($version->created_by_user_id)->toBe($this->admin->id);
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation', null));
    expect(AuditLog::where('action', 'regulation.created')->sole()->actor_user_id)->toBe($this->admin->id);
});

test('all regulation pages and writes reject non admin roles', function (string $role) {
    $version = regulationDraft($this->admin);
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    if ($user) {
        $this->actingAs($user);
    }
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach (['index', 'create', 'edit'] as $route) {
        if ($user) {
            $this->actingAs($user);
        }
        $this->get(route('admin.cms.regulations.'.$route, $route === 'edit' ? $version : []))->assertStatus($status);
    }
    foreach (['store' => 'post', 'update' => 'patch', 'publish' => 'post', 'destroy' => 'delete'] as $route => $method) {
        if ($user) {
            $this->actingAs($user);
        }
        $this->{$method}(route('admin.cms.regulations.'.$route, $route === 'store' ? [] : $version), regulationData())->assertStatus($status);
    }
    expect(RegulationVersion::count())->toBe(1)->and($version->fresh()->published_at)->toBeNull();
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('publication replaces current regulation and preserves immutable historical rows and owned images', function () {
    $first = regulationDraft($this->admin, regulationUpload());
    app(ManageRegulation::class)->publish($this->admin, $first->id, regulationRevision());
    $snapshot = $first->fresh()->getAttributes();
    $mediaId = $first->fresh()->media_asset_id;
    $this->actingAs($this->admin)->get(route('admin.cms.regulations.create'))->assertInertia(fn (Assert $page) => $page->where('initialBody', $first->body)->where('version', null));
    $second = app(ManageRegulation::class)->save($this->admin, null, regulationData(['body' => '<p>Nowe zasady.</p>']), null);
    $this->post(route('admin.cms.regulations.publish', $second), ['revision' => regulationRevision()])->assertSessionHasNoErrors()->assertRedirect();
    expect($first->fresh()->getAttributes())->toBe($snapshot)->and(MediaAsset::findOrFail($mediaId)->owner_id)->toBe($first->id)->and($second->fresh()->media_asset_id)->toBeNull();
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation.id', $second->id)->where('regulation.bodyHtml', '<p>Nowe zasady.</p>')->missing('regulation.created_by_user_id')->missing('regulation.body'));
    $this->get(route('admin.cms.regulations.index'))->assertInertia(fn (Assert $page) => $page->where('versions.data.0.status', 'current')->where('versions.data.1.status', 'history')->missing('versions.data.0.body'));
    $this->get(route('admin.cms.regulations.edit', $first))->assertInertia(fn (Assert $page) => $page->where('version.body', $first->body)->where('version.image.id', $mediaId)->missing('version.image.path'));
    $this->get(route('public.media.show', $mediaId))->assertNotFound();
    expect(AuditLog::where('action', 'regulation.published')->count())->toBe(2);
});

test('published version cannot be edited unpublished deleted or mutated through bulk and quiet paths', function (string $operation) {
    $version = regulationDraft($this->admin);
    app(ManageRegulation::class)->publish($this->admin, $version->id, regulationRevision());
    $version->refresh();
    if ($operation === 'bulk') {
        expect(fn () => RegulationVersion::whereKey($version->id)->update(['body' => 'Changed']))->toThrow(LogicException::class);
    } elseif ($operation === 'quiet') {
        expect(fn () => $version->forceFill(['published_at' => null, 'body' => 'Changed'])->saveQuietly())->toThrow(ValidationException::class);
    } elseif ($operation === 'model-delete') {
        expect(fn () => $version->deleteQuietly())->toThrow(ValidationException::class);
    } else {
        $this->actingAs($this->admin);
        $response = match ($operation) {
            'update' => $this->patch(route('admin.cms.regulations.update', $version), regulationData()),
            'publish' => $this->post(route('admin.cms.regulations.publish', $version), ['revision' => regulationRevision()]),
            default => $this->delete(route('admin.cms.regulations.destroy', $version), ['revision' => regulationRevision()]),
        };
        $response->assertSessionHasErrors('body');
    }
    expect($version->fresh()->body)->toBe('<h2>Zasady</h2><p>Treść regulaminu.</p>')->and($version->fresh()->published_at)->not->toBeNull()->and(RegulationVersion::count())->toBe(1);
})->with(['update', 'publish', 'delete', 'bulk', 'quiet', 'model-delete']);

test('stale create edit publish and delete are rejected', function (string $operation) {
    $version = regulationDraft($this->admin);
    $revision = regulationRevision();
    regulationDraft($this->admin);
    $this->actingAs($this->admin);
    $response = match ($operation) {
        'create' => $this->post(route('admin.cms.regulations.store'), regulationData(['revision' => $revision])),
        'update' => $this->patch(route('admin.cms.regulations.update', $version), regulationData(['revision' => $revision])),
        'publish' => $this->post(route('admin.cms.regulations.publish', $version), ['revision' => $revision]),
        default => $this->delete(route('admin.cms.regulations.destroy', $version), ['revision' => $revision]),
    };
    $response->assertSessionHasErrors('revision');
    expect(RegulationVersion::count())->toBe(2)->and($version->fresh()->published_at)->toBeNull();
})->with(['create', 'update', 'publish', 'delete']);

test('older draft cannot replace a newer published version', function () {
    $old = regulationDraft($this->admin);
    $new = regulationDraft($this->admin);
    app(ManageRegulation::class)->publish($this->admin, $new->id, regulationRevision());
    $this->actingAs($this->admin)->post(route('admin.cms.regulations.publish', $old), ['revision' => regulationRevision()])->assertSessionHasErrors('revision');
    expect($old->fresh()->published_at)->toBeNull();
    $this->get(route('admin.cms.regulations.index'))->assertInertia(fn (Assert $page) => $page->where('versions.data.1.canPublish', false));
});

test('invalid body and upload never create partial versions', function (string $kind) {
    $data = regulationData();
    if ($kind === 'body') {
        $data['body'] = '<script>alert(1)</script><p> </p>';
    } else {
        $data['image'] = UploadedFile::fake()->createWithContent('bad.svg', '<svg></svg>');
    }
    $this->actingAs($this->admin)->post(route('admin.cms.regulations.store'), $data)->assertSessionHasErrors($kind === 'body' ? 'body' : 'image');
    expect(RegulationVersion::count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(regulationRevision())->toBe(0);
})->with(['body', 'image']);

test('draft image can be replaced removed and deleted with post commit cleanup and shared revision checks', function () {
    $version = regulationDraft($this->admin, regulationUpload());
    $oldId = $version->media_asset_id;
    $oldPath = $version->media->path;
    $revision = regulationRevision();
    $this->actingAs($this->admin)->post(route('admin.cms.media.store', ['placement' => MediaPlacement::Regulation->value, 'owner' => $version->id]), ['current_media_id' => $oldId, 'image' => regulationUpload()])->assertSessionHasNoErrors();
    expect(regulationRevision())->toBe($revision + 1);
    Storage::disk('local')->assertExists($oldPath);
    expect(MediaFileDeletion::where('path', $oldPath)->exists())->toBeTrue();
    $this->patch(route('admin.cms.regulations.update', $version), regulationData(['revision' => $revision, 'current_media_id' => $oldId]))->assertSessionHasErrors('revision');
    $version->refresh();
    $this->patch(route('admin.cms.regulations.update', $version), regulationData(['current_media_id' => $version->media_asset_id, 'alt_text' => 'Nowy alt']))->assertSessionHasNoErrors()->assertRedirect();
    expect($version->media()->first()->alt_text)->toBe('Nowy alt');
    $this->patch(route('admin.cms.regulations.update', $version), regulationData(['current_media_id' => $version->media_asset_id, 'remove_image' => true]))->assertSessionHasNoErrors();
    expect($version->fresh()->media_asset_id)->toBeNull();
    $this->delete(route('admin.cms.regulations.destroy', $version), ['revision' => regulationRevision()])->assertSessionHasNoErrors();
    expect(RegulationVersion::count())->toBe(0)->and(MediaAsset::count())->toBe(0);
});

test('public and editor sanitize legacy HTML and do not expose drafts or archive endpoints', function () {
    $version = RegulationVersion::forceCreate(['version_number' => 1, 'body' => '<h2 onclick="bad()">Zasady</h2><script>bad()</script><p>Ważne <b>reguły</b></p>', 'published_at' => now()->subMinute(), 'created_by_user_id' => $this->admin->id]);
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation.bodyHtml', '<h2>Zasady</h2><p>Ważne <strong>reguły</strong></p>')->missing('regulation.bodyText'));
    $this->get('/regulamin/'.$version->id)->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.cms.regulations.edit', $version))->assertInertia(fn (Assert $page) => $page->where('version.body', '<h2>Zasady</h2><p>Ważne <strong>reguły</strong></p>'));
});

test('publication audit failure rolls back timestamp and state', function () {
    $version = regulationDraft($this->admin);
    $revision = regulationRevision();
    $this->mock(RecordAudit::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ManageRegulation::class)->publish($this->admin, $version->id, $revision))->toThrow(RuntimeException::class);
    expect($version->fresh()->published_at)->toBeNull()->and(regulationRevision())->toBe($revision);
});

test('business actions authorize independently and history is paginated without content or private metadata', function () {
    $employee = User::factory()->employee()->create();
    expect(fn () => app(ManageRegulation::class)->save($employee, null, regulationData(), null))->toThrow(AuthorizationException::class);
    for ($i = 0; $i < 21; $i++) {
        regulationDraft($this->admin);
    }
    $this->actingAs($this->admin)->get(route('admin.cms.regulations.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->where('versions.total', 21)->has('versions.data', 1)->where('versions.data.0.versionNumber', 1)->missing('versions.data.0.body')->missing('versions.data.0.created_by_user_id'));
});

test('two administrators cannot concurrently create or publish regulations', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locking is verified in the MySQL suite.');
    }
    $itemId = 0;
    if ($mode === 'publish') {
        $itemId = regulationDraft($this->admin)->id;
    }
    $revision = regulationRevision();
    $other = User::factory()->admin()->create();
    $barrier = tempnam(sys_get_temp_dir(), 'regulation-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
    $processes = [];
    try {
        foreach ([$this->admin, $other] as $index => $actor) {
            $worker = (string) ($index + 1);
            $process = new Process([PHP_BINARY, base_path('tests/Support/Cms/regulation-mutate.php'), $mode, (string) $actor->id, (string) $itemId, (string) $revision, $barrier, $worker], base_path(), $env, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.1') || ! file_exists($barrier.'.2')) && microtime(true) < $deadline) {
            usleep(10000);
        }touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        expect($results)->toBe(['conflict', 'success'])->and(regulationRevision())->toBe($revision + 1);
        if ($mode === 'create') {
            expect(RegulationVersion::count())->toBe(1);
        } else {
            expect(RegulationVersion::whereNotNull('published_at')->count())->toBe(1);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }foreach ([$barrier, $barrier.'.1', $barrier.'.2'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
})->with(['create', 'publish']);

test('draft save audit failure rolls back body asset and revision', function () {
    $revision = regulationRevision();
    $this->mock(RecordAudit::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ManageRegulation::class)->save($this->admin, null, regulationData(), regulationUpload()))->toThrow(ValidationException::class);
    expect(RegulationVersion::count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(regulationRevision())->toBe($revision);
    expect(MediaFileDeletion::count())->toBe(1);
});
