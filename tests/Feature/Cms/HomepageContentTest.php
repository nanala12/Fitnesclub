<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageCmsMedia;
use App\Actions\Cms\ManageHomepageContent;
use App\Enums\HomepageSection;
use App\Enums\MediaPlacement;
use App\Jobs\DeleteCmsMediaFile;
use App\Models\AuditLog;
use App\Models\HeroSlide;
use App\Models\HomepageContentList;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\OfferTile;
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
function homePhoto(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function homePayload(HomepageSection $section, array $overrides = []): array
{
    return ['title' => 'Test title', 'description' => 'Test description', 'link_url' => '/cennik', 'is_published' => false,
        'revision' => HomepageContentList::findOrFail($section->value)->revision, 'current_media_id' => null, 'remove_image' => false, 'alt_text' => 'Test photo', ...$overrides];
}
function homeRoute(string $action, HomepageSection $section, ?int $item = null, array $query = []): string
{
    return route('admin.cms.homepage.'.$action, ['section' => $section, ...($item === null ? [] : ['item' => $item]), ...$query]);
}
function homeCreate(User $admin, HomepageSection $section, array $overrides = []): OfferTile|HeroSlide
{
    return app(ManageHomepageContent::class)->save($admin, $section, null, homePayload($section, $overrides), $section === HomepageSection::Slides ? homePhoto() : null);
}

test('admins can browse empty lists and blank create forms', function (HomepageSection $section) {
    $this->actingAs($this->admin)->get(homeRoute('index', $section))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/cms/homepage/Index')->where('section', $section->value)->where('revision', 0)->where('items.total', 0));
    $this->get(homeRoute('create', $section))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/cms/homepage/Form')->where('item', null));
})->with(HomepageSection::cases());

test('non administrators cannot read or mutate any homepage editor', function (string $role) {
    $section = HomepageSection::Tiles;
    $item = homeCreate($this->admin, $section);
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]),
        'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach (['index', 'create', 'edit'] as $action) {
        if ($user) {
            $this->actingAs($user);
        }
        $this->get(homeRoute($action, $section, $action === 'edit' ? $item->id : null))->assertStatus($status);
    }
    foreach (['store', 'update', 'visibility', 'move', 'destroy'] as $action) {
        if ($user) {
            $this->actingAs($user);
        }
        $method = match ($action) {
            'store' => 'post', 'destroy' => 'delete', default => 'patch'
        };
        $this->{$method}(homeRoute($action, $section, $action === 'store' ? null : $item->id), homePayload($section))->assertStatus($status);
    }
    expect($item->fresh()->title)->toBe('Test title')->and(OfferTile::count())->toBe(1);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('new hidden tiles and slides store owned uploads and safe audit details', function (HomepageSection $section) {
    $this->actingAs($this->admin)->post(homeRoute('store', $section), homePayload($section, ['image' => homePhoto(), 'media_asset_id' => 99999, 'sort_order' => 99999]))->assertRedirect(homeRoute('index', $section))->assertSessionHasNoErrors();
    $item = $section->model()::sole();
    expect($item->is_published)->toBeFalse()->and($item->sort_order)->toBe(0)->and($item->media_asset_id)->not->toBe(99999);
    expect($item->media->owner_type)->toBe($section === HomepageSection::Tiles ? 'offer' : 'slide')->and($item->media->owner_id)->toBe($item->id);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('tiles', [])->where('slides', []));
    $this->get(homeRoute('edit', $section, $item->id))->assertInertia(fn (Assert $page) => $page->where('item.image.alt', 'Test photo')->missing('item.image.path')->missing('item.image.disk')->missing('item.uploaded_by_user_id'));
    $entry = AuditLog::where('action', $section->audit('created')->value)->sole();
    expect($entry->actor_user_id)->toBe($this->admin->id)->and(json_encode($entry->getAttributes()))->not->toContain('Test title')->not->toContain($item->media->path);
})->with(HomepageSection::cases());

test('tile can be created without image while slide image is required', function () {
    $this->actingAs($this->admin)->post(homeRoute('store', HomepageSection::Tiles), homePayload(HomepageSection::Tiles))->assertSessionHasNoErrors();
    expect(OfferTile::sole()->media_asset_id)->toBeNull();
    $this->post(homeRoute('store', HomepageSection::Slides), homePayload(HomepageSection::Slides))->assertSessionHasErrors('image');
    expect(HeroSlide::count())->toBe(0);
});

test('form validation rejects unsafe links oversized text and invalid uploads without content writes', function (string $kind) {
    $section = HomepageSection::Tiles;
    $invalid = match ($kind) {
        'title' => ['title' => ''], 'description' => ['description' => str_repeat('x', 501)],
        'link_js' => ['link_url' => 'javascript:alert(1)'], 'link_external' => ['link_url' => 'https://example.com'], 'link_relative' => ['link_url' => '//example.com'],
        'image' => ['image' => UploadedFile::fake()->createWithContent('fake.png', '<script>bad</script>')],
        'revision' => ['revision' => -1], 'remove_image' => ['image' => homePhoto(), 'remove_image' => true],
    };
    $field = str_starts_with($kind, 'link_') ? 'link_url' : ($kind === 'remove_image' ? 'image' : $kind);
    $this->actingAs($this->admin)->post(homeRoute('store', $section), homePayload($section, $invalid))->assertSessionHasErrors($field);
    expect(OfferTile::count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(AuditLog::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(0);
})->with(['title', 'description', 'link_js', 'link_external', 'link_relative', 'image', 'revision', 'remove_image']);

test('published content reflects edits visibility and safe plain descriptions', function (HomepageSection $section) {
    $item = homeCreate($this->admin, $section);
    $data = homePayload($section, ['title' => 'Updated title', 'description' => '<script>alert(1)</script>Opis', 'is_published' => true, 'current_media_id' => $item->media_asset_id]);
    $this->actingAs($this->admin)->patch(homeRoute('update', $section, $item->id), $data)->assertRedirect(homeRoute('index', $section))->assertSessionHasNoErrors();
    $key = $section === HomepageSection::Tiles ? 'tiles' : 'slides';
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has($key, 1)->where($key.'.0.title', 'Updated title')->where($key.'.0.description', 'alert(1)Opis'));
    $this->patch(homeRoute('visibility', $section, $item->id), ['revision' => HomepageContentList::findOrFail($section->value)->revision, 'is_published' => false])->assertSessionHasNoErrors();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where($key, []));
    if ($item->media_asset_id !== null) {
        $this->get(route('public.media.show', $item->media_asset_id))->assertNotFound();
    }
})->with(HomepageSection::cases());

test('reorder derives the whole list including hidden entries and persists canonical positions', function (HomepageSection $section) {
    $first = homeCreate($this->admin, $section, ['title' => 'First', 'is_published' => true]);
    $hidden = homeCreate($this->admin, $section, ['title' => 'Hidden']);
    $last = homeCreate($this->admin, $section, ['title' => 'Last', 'is_published' => true]);
    $this->actingAs($this->admin)->patch(homeRoute('move', $section, $last->id), ['direction' => 'up', 'revision' => HomepageContentList::findOrFail($section->value)->revision, 'ids' => [999, $first->id]])->assertSessionHasNoErrors();
    expect($section->model()::orderBy('sort_order')->pluck('id')->all())->toBe([$first->id, $last->id, $hidden->id]);
    expect($section->model()::orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1, 2]);
    $this->patch(homeRoute('move', $section, $last->id), ['direction' => 'up', 'revision' => HomepageContentList::findOrFail($section->value)->revision])->assertSessionHasNoErrors();
    $key = $section === HomepageSection::Tiles ? 'tiles' : 'slides';
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where($key.'.0.id', $last->id)->where($key.'.1.id', $first->id)->has($key, 2));
    expect(AuditLog::where('action', $section->audit('reordered')->value)->count())->toBe(2);
})->with(HomepageSection::cases());

test('invalid direction boundaries and missing items cannot change order', function () {
    $section = HomepageSection::Tiles;
    $item = homeCreate($this->admin, $section);
    $revision = HomepageContentList::findOrFail($section->value)->revision;
    $this->actingAs($this->admin)->patch(homeRoute('move', $section, $item->id), ['direction' => 'sideways', 'revision' => $revision])->assertSessionHasErrors('direction');
    $this->patch(homeRoute('move', $section, $item->id), ['direction' => 'up', 'revision' => $revision])->assertSessionHasErrors('direction');
    $this->patch(homeRoute('move', $section, 99999), ['direction' => 'down', 'revision' => $revision])->assertNotFound();
    $this->get(homeRoute('edit', $section, 99999))->assertNotFound();
    $this->get('/admin/cms/strona-glowna/unknown')->assertNotFound();
    expect(HomepageContentList::findOrFail($section->value)->revision)->toBe($revision)->and($item->fresh()->sort_order)->toBe(0);
});

test('list pagination keeps global movement and can move across page boundary', function () {
    foreach (range(1, 21) as $number) {
        OfferTile::forceCreate(['title' => 'Tile '.$number, 'sort_order' => $number]);
    }
    $item = OfferTile::orderByDesc('sort_order')->firstOrFail();
    $this->actingAs($this->admin)->get(homeRoute('index', HomepageSection::Tiles, query: ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.total', 21)->where('items.current_page', 2));
    $this->patch(homeRoute('move', HomepageSection::Tiles, $item->id), ['revision' => 0, 'direction' => 'up', 'page' => 2])->assertRedirect(homeRoute('index', HomepageSection::Tiles, query: ['page' => 2]));
    expect(OfferTile::orderBy('sort_order')->pluck('id')->all()[19])->toBe($item->id);
});

test('stale forms cannot overwrite remove hide or reorder updated content', function (string $action) {
    $section = HomepageSection::Tiles;
    $first = homeCreate($this->admin, $section);
    $stale = HomepageContentList::findOrFail($section->value)->revision;
    homeCreate($this->admin, $section, ['title' => 'New item']);
    $method = $action === 'destroy' ? 'delete' : 'patch';
    $this->actingAs($this->admin)->{$method}(homeRoute($action, $section, $first->id), homePayload($section, ['revision' => $stale, 'title' => 'Stale title', 'direction' => 'down', 'is_published' => true]))->assertSessionHasErrors('revision');
    expect($first->fresh()->title)->toBe('Test title')->and($first->fresh()->is_published)->toBeFalse()->and(OfferTile::count())->toBe(2);
})->with(['update', 'destroy', 'visibility', 'move']);

test('replacement updates content and photo together and retires previous file', function (HomepageSection $section) {
    $item = app(ManageHomepageContent::class)->save($this->admin, $section, null, homePayload($section), homePhoto());
    $old = $item->media;
    $this->actingAs($this->admin)->post(homeRoute('update', $section, $item->id), homePayload($section, ['_method' => 'patch', 'title' => 'New title', 'current_media_id' => $old->id, 'image' => homePhoto()]))->assertRedirect(homeRoute('index', $section))->assertSessionHasNoErrors();
    $item->refresh();
    expect($item->title)->toBe('New title')->and($item->media_asset_id)->not->toBe($old->id)->and(MediaAsset::find($old->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    Storage::disk('local')->assertExists($old->path);
    Queue::assertPushed(DeleteCmsMediaFile::class, 1);
})->with(HomepageSection::cases());

test('optional image can be removed while title remains and slide photo cannot be removed alone', function (HomepageSection $section) {
    $item = app(ManageHomepageContent::class)->save($this->admin, $section, null, homePayload($section), homePhoto());
    $data = homePayload($section, ['current_media_id' => $item->media_asset_id, 'remove_image' => true]);
    $response = $this->actingAs($this->admin)->patch(homeRoute('update', $section, $item->id), $data);
    if ($section === HomepageSection::Slides) {
        $response->assertSessionHasErrors('remove_image');
        expect($item->fresh()->media_asset_id)->not->toBeNull();
    } else {
        $response->assertSessionHasNoErrors();
        expect($item->fresh()->title)->toBe('Test title')->and($item->fresh()->media_asset_id)->toBeNull()->and(MediaAsset::count())->toBe(0);
    }
})->with(HomepageSection::cases());

test('alternative text can be changed without replacing image', function () {
    $section = HomepageSection::Slides;
    $item = homeCreate($this->admin, $section);
    $this->actingAs($this->admin)->patch(homeRoute('update', $section, $item->id), homePayload($section, ['current_media_id' => $item->media_asset_id, 'alt_text' => 'New accessible description']))->assertSessionHasNoErrors();
    expect($item->fresh()->media->alt_text)->toBe('New accessible description')->and(MediaAsset::count())->toBe(1)->and(MediaFileDeletion::count())->toBe(0);
});

test('delete removes content and image and clamps last page after removal', function (HomepageSection $section) {
    $item = app(ManageHomepageContent::class)->save($this->admin, $section, null, homePayload($section), homePhoto());
    $asset = $item->media;
    $this->actingAs($this->admin)->delete(homeRoute('destroy', $section, $item->id), ['revision' => HomepageContentList::findOrFail($section->value)->revision, 'current_media_id' => $asset->id, 'page' => 5])->assertRedirect(homeRoute('index', $section, query: ['page' => 1]));
    expect($item->fresh())->toBeNull()->and(MediaAsset::find($asset->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    $this->get(route('public.media.show', $asset->id))->assertNotFound();
    expect(AuditLog::where('action', $section->audit('deleted')->value)->sole()->actor_user_id)->toBe($this->admin->id);
})->with(HomepageSection::cases());

test('audit failure rolls content revision and asset changes back', function () {
    $section = HomepageSection::Slides;
    $item = homeCreate($this->admin, $section);
    $oldId = $item->media_asset_id;
    $revision = HomepageContentList::findOrFail($section->value)->revision;
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Audit failure'));
    expect(fn () => app(ManageHomepageContent::class)->save($this->admin, $section, $item->id, homePayload($section, ['title' => 'Rolled back', 'current_media_id' => $oldId]), homePhoto()))->toThrow(ValidationException::class);
    expect($item->fresh()->title)->toBe('Test title')->and($item->fresh()->media_asset_id)->toBe($oldId)->and(HomepageContentList::findOrFail($section->value)->revision)->toBe($revision)->and(MediaAsset::count())->toBe(1);
    expect(MediaFileDeletion::sole()->path)->not->toBe($item->fresh()->media->path);
});

test('shared media endpoints invalidate stale homepage forms and use list revision', function () {
    $section = HomepageSection::Slides;
    $item = homeCreate($this->admin, $section);
    $revision = HomepageContentList::findOrFail($section->value)->revision;
    app(ManageCmsMedia::class)->replace($this->admin, MediaPlacement::Slide, $item->id, $item->media_asset_id, homePhoto(), null);
    expect(HomepageContentList::findOrFail($section->value)->revision)->toBe($revision + 1);
    $this->actingAs($this->admin)->patch(homeRoute('update', $section, $item->id), homePayload($section, ['revision' => $revision, 'current_media_id' => $item->media_asset_id]))->assertSessionHasErrors('revision');
});

test('business actions authorize callers independently from controller middleware', function () {
    $customer = User::factory()->create();
    expect(fn () => app(ManageHomepageContent::class)->save($customer, HomepageSection::Tiles, null, homePayload(HomepageSection::Tiles), null))->toThrow(AuthorizationException::class);
    expect(OfferTile::count())->toBe(0);
});

test('concurrent empty list creation and reorder are serialized with revision conflicts', function (string $operation) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locks are covered by the MySQL suite.');
    }
    $section = HomepageSection::Tiles;
    $item = null;
    if ($operation === 'move') {
        $item = homeCreate($this->admin, $section, ['title' => 'First']);
        homeCreate($this->admin, $section, ['title' => 'Second']);
    }
    $revision = HomepageContentList::findOrFail($section->value)->revision;
    $barrier = tempnam(sys_get_temp_dir(), 'home-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
    $processes = [];
    try {
        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Cms/homepage-mutate.php'), $operation, (string) $this->admin->id, (string) ($item?->id ?? 0), (string) $revision, $barrier, (string) $worker], base_path(), $env, timeout: 15);
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
        expect($results)->toBe(['conflict', 'success'])->and(HomepageContentList::findOrFail($section->value)->revision)->toBe($revision + 1);
        expect(OfferTile::orderBy('sort_order')->pluck('sort_order')->all())->toBe($operation === 'create' ? [0] : [0, 1]);
        if ($operation === 'move') {
            expect(OfferTile::orderBy('sort_order')->first()->title)->toBe('Second');
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.1', $barrier.'.2'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
})->with(['create', 'move']);

test('image previews do not consume the content modification quota', function () {
    $section = HomepageSection::Tiles;
    $item = app(ManageHomepageContent::class)->save($this->admin, $section, null, homePayload($section), homePhoto());
    $this->actingAs($this->admin);
    foreach (range(1, 31) as $request) {
        $this->get(route('admin.cms.media.show', $item->media_asset_id))->assertOk();
    }
    $this->patch(homeRoute('update', $section, $item->id), homePayload($section, ['current_media_id' => $item->media_asset_id, 'title' => 'After previews']))->assertRedirect()->assertSessionHasNoErrors();
    expect($item->fresh()->title)->toBe('After previews');
});
