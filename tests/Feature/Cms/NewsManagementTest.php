<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageCmsMedia;
use App\Actions\Cms\ManageNewsPost;
use App\Enums\MediaPlacement;
use App\Jobs\DeleteCmsMediaFile;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\NewsPost;
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
function newsImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('news.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function newsData(?NewsPost $post = null, array $overrides = []): array
{
    return ['title' => 'Wiadomość z klubu', 'excerpt' => 'Krótki wstęp', 'body' => '<p>Treść <strong>ważna</strong>.</p>', 'published_at' => null,
        'is_published' => false, 'revision' => $post?->revision ?? 0, 'current_media_id' => $post?->cover_media_id,
        'remove_image' => false, 'alt_text' => 'Zdjęcie do wpisu', ...$overrides];
}
function createNews(User $actor, array $overrides = [], bool $image = false): NewsPost
{
    return app(ManageNewsPost::class)->save($actor, null, newsData(overrides: $overrides), $image ? newsImage() : null);
}

test('news editor lists actual empty state and create form for admin', function () {
    $this->actingAs($this->admin)->get(route('admin.cms.news.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/cms/news/Index')->where('posts.total', 0));
    $this->get(route('admin.cms.news.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/cms/news/Form')->where('post', null));
});

test('news read and mutation endpoints reject non administrators and suspended sessions', function (string $role) {
    $post = createNews($this->admin);
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach (['index', 'create', 'edit'] as $action) {
        if ($user) {
            $this->actingAs($user);
        }
        $this->get(route('admin.cms.news.'.$action, $action === 'edit' ? $post : []))->assertStatus($status);
    }
    foreach (['store', 'update', 'visibility', 'destroy'] as $action) {
        if ($user) {
            $this->actingAs($user);
        }
        $method = match ($action) {
            'store' => 'post', 'destroy' => 'delete', default => 'patch'
        };
        $this->{$method}(route('admin.cms.news.'.$action, $action === 'store' ? [] : $post), newsData($post))->assertStatus($status);
    }
    expect(NewsPost::count())->toBe(1)->and($post->fresh()->title)->toBe('Wiadomość z klubu');
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('draft creation sanitizes HTML stores authors owned image and redirects to index', function () {
    $data = newsData(overrides: ['body' => '<h2>Nagłówek</h2><p onclick="alert(1)"><b>Ważne</b><script>unsafe()</script></p>', 'image' => newsImage(), 'created_by_user_id' => 999, 'cover_media_id' => 999, 'slug' => 'client-slug']);
    $this->actingAs($this->admin)->post(route('admin.cms.news.store'), $data)->assertRedirect(route('admin.cms.news.index'))->assertSessionHasNoErrors();
    $post = NewsPost::sole();
    expect($post->body)->toBe('<h2>Nagłówek</h2><p><strong>Ważne</strong></p>')->and($post->is_published)->toBeFalse()->and($post->published_at)->toBeNull()
        ->and($post->created_by_user_id)->toBe($this->admin->id)->and($post->updated_by_user_id)->toBe($this->admin->id)->and($post->slug)->not->toBe('client-slug');
    expect($post->media->owner_type)->toBe('news')->and($post->media->owner_id)->toBe($post->id);
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
    $this->get(route('admin.cms.news.edit', $post))->assertInertia(fn (Assert $page) => $page->where('post.body', $post->body)->missing('post.image.path')->missing('post.image.disk')->missing('post.created_by_user_id'));
    $audit = AuditLog::where('action', 'news.created')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id)->and($audit->subject_type)->toBe('news_post');
    expect(json_encode($audit->getAttributes()))->not->toContain($post->body)->not->toContain($post->media->path);
});

test('empty sanitized body unsafe upload and invalid fields leave no records', function (string $kind) {
    $overrides = match ($kind) {
        'title' => ['title' => ''], 'body' => ['body' => '<script>onlyScript()</script><p><br></p>'], 'empty' => ['body' => ''],
        'length' => ['body' => str_repeat('a', 50001)], 'date' => ['published_at' => '2026-02-30T12:00'],
        'image' => ['image' => UploadedFile::fake()->createWithContent('news.png', '<svg onload="alert(1)"/>')],
        'remove' => ['image' => newsImage(), 'remove_image' => true],
    };
    $field = match ($kind) {
        'length','empty' => 'body', 'date' => 'published_at', 'remove' => 'image', default => $kind
    };
    $this->actingAs($this->admin)->post(route('admin.cms.news.store'), newsData(overrides: $overrides))->assertSessionHasErrors($field);
    expect(NewsPost::count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(0)->and(AuditLog::count())->toBe(0);
})->with(['title', 'body', 'empty', 'length', 'date', 'image', 'remove']);

test('publish now and hide control public detail list and image access', function () {
    $this->freezeTime();
    $post = createNews($this->admin, ['is_published' => true], true);
    expect($post->published_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    $this->get(route('public.news.show', $post->slug))->assertOk()->assertInertia(fn (Assert $page) => $page->where('post.bodyHtml', '<p>Treść <strong>ważna</strong>.</p>')->missing('post.revision'));
    $this->get(route('public.media.show', $post->cover_media_id))->assertOk();
    $this->actingAs($this->admin)->patch(route('admin.cms.news.visibility', $post), ['revision' => $post->revision, 'is_published' => false])->assertSessionHasNoErrors();
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
    $this->get(route('public.media.show', $post->cover_media_id))->assertNotFound();
    $this->get(route('public.news.index'))->assertInertia(fn (Assert $page) => $page->where('posts.total', 0));
    expect(MediaAsset::count())->toBe(1);
});

test('future publication respects Warsaw conversion and becomes public without rewriting content', function () {
    $this->travelTo(now()->setDateTime(2026, 11, 18, 10, 0, 0));
    $post = createNews($this->admin, ['is_published' => true, 'published_at' => '2026-11-19T15:00']);
    expect($post->published_at->format('Y-m-d H:i'))->toBe('2026-11-19 14:00');
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.cms.news.index'))->assertInertia(fn (Assert $page) => $page->where('posts.data.0.status', 'scheduled')->where('posts.data.0.publicationLocal', '2026-11-19T15:00')->missing('posts.data.0.body'));
    $revision = $post->revision;
    $this->travelTo($post->published_at);
    $this->get(route('public.news.show', $post->slug))->assertOk();
    expect($post->fresh()->revision)->toBe($revision);
});

test('future date on draft remains hidden even after time passes and visibility keeps scheduled date', function () {
    $post = createNews($this->admin, ['published_at' => '2030-07-15T12:00']);
    $this->actingAs($this->admin)->patch(route('admin.cms.news.visibility', $post), ['revision' => $post->revision, 'is_published' => true])->assertSessionHasNoErrors();
    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2030-07-15 10:00');
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
});

test('editing keeps stable slug creator and changes updater and image atomically', function () {
    $post = createNews($this->admin, image: true);
    $old = $post->media;
    $second = User::factory()->admin()->create();
    $this->actingAs($second)->post(route('admin.cms.news.update', $post), newsData($post, ['_method' => 'patch', 'title' => 'Zmieniony tytuł', 'image' => newsImage()]))->assertRedirect(route('admin.cms.news.index'))->assertSessionHasNoErrors();
    $updated = $post->fresh();
    expect($updated->slug)->toBe($post->slug)->and($updated->title)->toBe('Zmieniony tytuł')->and($updated->created_by_user_id)->toBe($this->admin->id)->and($updated->updated_by_user_id)->toBe($second->id)
        ->and($updated->revision)->toBe(1)->and($updated->cover_media_id)->not->toBe($old->id)->and(MediaAsset::find($old->id))->toBeNull();
    Storage::disk('local')->assertExists($old->path);
    Queue::assertPushed(DeleteCmsMediaFile::class, 1);
});

test('stale update hide and delete cannot overwrite a newer news version', function (string $action) {
    $post = createNews($this->admin);
    app(ManageNewsPost::class)->save($this->admin, $post->id, newsData($post, ['title' => 'Fresh version']), null);
    $method = $action === 'destroy' ? 'delete' : 'patch';
    $this->actingAs($this->admin)->{$method}(route('admin.cms.news.'.$action, $post), newsData($post))->assertSessionHasErrors('revision');
    expect($post->fresh()->title)->toBe('Fresh version')->and($post->fresh()->is_published)->toBeFalse();
})->with(['update', 'visibility', 'destroy']);

test('shared media updates invalidate news forms and record actor', function () {
    $post = createNews($this->admin, image: true);
    app(ManageCmsMedia::class)->replace($this->admin, MediaPlacement::News, $post->id, $post->cover_media_id, newsImage(), null);
    expect($post->fresh()->revision)->toBe(1)->and($post->fresh()->updated_by_user_id)->toBe($this->admin->id);
    $this->actingAs($this->admin)->patch(route('admin.cms.news.update', $post), newsData($post))->assertSessionHasErrors('revision');
});

test('image removal keeps news body while deletion retires the owned file', function (bool $removeOnly) {
    $post = createNews($this->admin, ['is_published' => true], true);
    $asset = $post->media;
    if ($removeOnly) {
        $this->actingAs($this->admin)->patch(route('admin.cms.news.update', $post), newsData($post, ['remove_image' => true]))->assertSessionHasNoErrors();
        expect($post->fresh()->cover_media_id)->toBeNull()->and($post->fresh()->body)->toBe($post->body);
    } else {
        $this->actingAs($this->admin)->delete(route('admin.cms.news.destroy', $post), newsData($post))->assertRedirect();
        expect($post->fresh())->toBeNull();
    }
    expect(MediaAsset::find($asset->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    $this->get(route('public.media.show', $asset->id))->assertNotFound();
})->with([true, false]);

test('audit failure rolls news revision author and photo replacement back', function () {
    $post = createNews($this->admin, image: true);
    $oldId = $post->cover_media_id;
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ManageNewsPost::class)->save($this->admin, $post->id, newsData($post, ['title' => 'Rollback']), newsImage()))->toThrow(ValidationException::class);
    expect($post->fresh()->title)->toBe($post->title)->and($post->fresh()->revision)->toBe(0)->and($post->fresh()->cover_media_id)->toBe($oldId)->and(MediaAsset::count())->toBe(1);
    expect(MediaFileDeletion::sole()->path)->not->toBe($post->fresh()->media->path);
});

test('equal titles get unique stable slugs and admin pagination excludes body and storage data', function () {
    foreach (range(1, 21) as $index) {
        createNews($this->admin);
    }
    expect(NewsPost::distinct()->count('slug'))->toBe(21);
    $this->actingAs($this->admin)->get(route('admin.cms.news.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->where('posts.total', 21)->has('posts.data', 1)->missing('posts.data.0.body')->missing('posts.data.0.created_by_user_id'));
});

test('legacy unsanitized news is sanitized at both editor and public read boundary', function () {
    $post = NewsPost::forceCreate(['title' => 'Legacy', 'slug' => 'legacy', 'body' => '<p onmouseover="alert(1)">Text</p><script>pwned()</script>', 'is_published' => true, 'published_at' => now()]);
    $this->get(route('public.news.show', $post->slug))->assertInertia(fn (Assert $page) => $page->where('post.bodyHtml', '<p>Text</p>'));
    $this->actingAs($this->admin)->get(route('admin.cms.news.edit', $post))->assertInertia(fn (Assert $page) => $page->where('post.body', '<p>Text</p>'));
});

test('business action denies customer independent of controller middleware', function () {
    $customer = User::factory()->create();
    expect(fn () => app(ManageNewsPost::class)->save($customer, null, newsData(), null))->toThrow(AuthorizationException::class);
    expect(NewsPost::count())->toBe(0);
});

test('concurrent editors cannot overwrite the same news revision', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locks are verified in the MySQL suite.');
    }
    $post = createNews($this->admin);
    $barrier = tempnam(sys_get_temp_dir(), 'news-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Cms/news-update.php'), (string) $this->admin->id, (string) $post->id, (string) $post->revision, $barrier, (string) $worker], base_path(), $environment, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.1') || ! file_exists($barrier.'.2')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        expect($results)->toBe(['conflict', 'success'])->and($post->fresh()->revision)->toBe(1)->and(AuditLog::where('action', 'news.updated')->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.1', $barrier.'.2'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});

test('publishing legacy news with no text after sanitation fails without changing revision or audit', function () {
    $post = NewsPost::forceCreate(['title' => 'Legacy draft', 'slug' => 'legacy-empty-draft', 'body' => '<script>unsafe()</script><p> </p>', 'is_published' => false,
        'created_by_user_id' => $this->admin->id, 'updated_by_user_id' => $this->admin->id]);
    $this->actingAs($this->admin)->patch(route('admin.cms.news.visibility', $post), ['revision' => 0, 'is_published' => true])->assertSessionHasErrors('body');
    expect($post->fresh()->is_published)->toBeFalse()->and($post->fresh()->revision)->toBe(0)->and($post->fresh()->published_at)->toBeNull()->and(AuditLog::count())->toBe(0);
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
    $this->patch(route('admin.cms.news.visibility', $post), ['revision' => 0, 'is_published' => false])->assertSessionHasNoErrors();
    expect($post->fresh()->is_published)->toBeFalse()->and($post->fresh()->revision)->toBe(1);
});
