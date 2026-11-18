<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageCmsMedia;
use App\Actions\Cms\ManageGallery;
use App\Enums\MediaPlacement;
use App\Jobs\DeleteCmsMediaFile;
use App\Models\AuditLog;
use App\Models\GalleryCategory;
use App\Models\GalleryContentState;
use App\Models\GalleryImage;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
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
function galleryRevision(): int
{
    return GalleryContentState::findOrFail(1)->revision;
}
function galleryUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('gallery.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function galleryCategory(User $actor, string $name = 'Test category', bool $published = false): GalleryCategory
{
    return app(ManageGallery::class)->saveCategory($actor, null, ['name' => $name, 'short_description' => 'Category description', 'is_published' => $published, 'revision' => galleryRevision()]);
}
function galleryPhoto(User $actor, GalleryCategory $category, bool $published = false): GalleryImage
{
    return app(ManageGallery::class)->saveImage($actor, null, ['gallery_category_id' => $category->id, 'is_published' => $published, 'revision' => galleryRevision(), 'current_media_id' => null, 'alt_text' => 'Accessible photo'], galleryUpload());
}

test('admin can read empty gallery and forms with no category prerequisite', function () {
    $this->actingAs($this->admin)->get(route('admin.cms.gallery.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/cms/gallery/Index')->where('categories.total', 0)->where('images.total', 0)->where('revision', 0));
    $this->get(route('admin.cms.gallery.categories.create'))->assertInertia(fn (Assert $page) => $page->where('category', null));
    $this->get(route('admin.cms.gallery.images.create'))->assertInertia(fn (Assert $page) => $page->where('categoryOptions', [])->where('image', null));
});

test('non administrators cannot access category or image operations', function (string $role) {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(), 'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach (['categories', 'images'] as $type) {
        $item = $type === 'categories' ? $category : $photo;
        foreach (['create', 'edit'] as $action) {
            if ($user) {
                $this->actingAs($user);
            } $this->get(route('admin.cms.gallery.'.$type.'.'.$action, $action === 'edit' ? $item : []))->assertStatus($status);
        }
        foreach (['store', 'update', 'visibility', 'move', 'destroy'] as $action) {
            if ($user) {
                $this->actingAs($user);
            }
            $method = match ($action) {
                'store' => 'post','destroy' => 'delete',default => 'patch'
            };
            $this->{$method}(route('admin.cms.gallery.'.$type.'.'.$action, $action === 'store' ? [] : $item), ['revision' => galleryRevision()])->assertStatus($status);
        }
    }
    if ($user) {
        $this->actingAs($user);
    } $this->get(route('admin.cms.gallery.index'))->assertStatus($status);
    expect(GalleryCategory::count())->toBe(1)->and(GalleryImage::count())->toBe(1);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('category create and edit save plain metadata and return to index', function () {
    $this->actingAs($this->admin)->post(route('admin.cms.gallery.categories.store'), ['name' => 'Sala', 'description' => 'Krótki opis', 'is_published' => false, 'revision' => 0, 'sort_order' => 999])->assertRedirect()->assertSessionHasNoErrors();
    $category = GalleryCategory::sole();
    expect($category->short_description)->toBe('Krótki opis')->and($category->sort_order)->toBe(0)->and($category->is_published)->toBeFalse();
    $this->patch(route('admin.cms.gallery.categories.update', $category), ['name' => 'Sala treningowa', 'description' => 'Nowy opis', 'is_published' => true, 'revision' => galleryRevision()])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->where('categories.0.name', 'Sala treningowa')->where('categories.0.description', 'Nowy opis'));
    expect(AuditLog::where('action', 'gallery.category_created')->sole()->actor_user_id)->toBe($this->admin->id);
});

test('category and upload validation prevents partial or invalid records', function (string $kind) {
    $category = galleryCategory($this->admin);
    $data = ['gallery_category_id' => $category->id, 'is_published' => false, 'current_media_id' => null, 'revision' => galleryRevision(), 'image' => galleryUpload()];
    if ($kind === 'name' || $kind === 'description') {
        $this->actingAs($this->admin)->post(route('admin.cms.gallery.categories.store'), ['name' => $kind === 'name' ? '' : 'Valid', 'description' => $kind === 'description' ? str_repeat('a', 501) : '', 'is_published' => false, 'revision' => galleryRevision()])->assertSessionHasErrors($kind);
    } else {
        $data = match ($kind) {
            'category' => [...$data, 'gallery_category_id' => 99999], 'image' => [...$data, 'image' => null], 'spoof' => [...$data, 'image' => UploadedFile::fake()->createWithContent('gallery.png', '<script>bad</script>')], default => $data
        };
        $this->actingAs($this->admin)->post(route('admin.cms.gallery.images.store'), $data)->assertSessionHasErrors($kind === 'category' ? 'gallery_category_id' : 'image');
    }
    expect(GalleryCategory::count())->toBe(1)->and(GalleryImage::count())->toBe(0)->and(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(0);
})->with(['name', 'description', 'category', 'image', 'spoof']);

test('new photos are explicit drafts with one owner no editorial descriptions and private DTO', function () {
    $category = galleryCategory($this->admin, 'Published', true);
    $this->actingAs($this->admin)->post(route('admin.cms.gallery.images.store'), ['gallery_category_id' => $category->id, 'is_published' => false, 'current_media_id' => null, 'revision' => galleryRevision(), 'image' => galleryUpload(), 'alt_text' => 'Accessible photo', 'title' => 'Ignored', 'description' => 'Ignored', 'caption' => 'Ignored', 'media_asset_id' => 999])->assertRedirect()->assertSessionHasNoErrors();
    $photo = GalleryImage::sole();
    expect($photo->is_published)->toBeFalse()->and($photo->media->owner_type)->toBe('gallery')->and($photo->media->owner_id)->toBe($photo->id);
    expect($photo->getAttributes())->not->toHaveKeys(['title', 'caption', 'description']);
    $this->get(route('admin.cms.gallery.images.edit', $photo))->assertInertia(fn (Assert $page) => $page->where('image.image.alt', 'Accessible photo')->missing('image.image.path')->missing('image.image.disk')->missing('image.title')->missing('image.description')->missing('image.caption'));
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->where('images.total', 0));
    $this->get(route('public.media.show', $photo->media_asset_id))->assertNotFound();
});

test('image and category must both be published to expose gallery and image route', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category, true);
    $this->get(route('public.media.show', $photo->media_asset_id))->assertNotFound();
    $this->actingAs($this->admin)->patch(route('admin.cms.gallery.categories.visibility', $category), ['revision' => galleryRevision(), 'is_published' => true])->assertSessionHasNoErrors();
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->where('images.total', 1));
    $this->get(route('public.media.show', $photo->media_asset_id))->assertOk();
    $this->patch(route('admin.cms.gallery.images.visibility', $photo), ['revision' => galleryRevision(), 'is_published' => false])->assertSessionHasNoErrors();
    $this->get(route('public.media.show', $photo->media_asset_id))->assertNotFound();
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->where('images.total', 0));
    expect(MediaAsset::count())->toBe(1);
});

test('category hiding revokes published photo access and hidden category cannot be selected publicly', function () {
    $category = galleryCategory($this->admin, 'Public', true);
    $photo = galleryPhoto($this->admin, $category, true);
    app(ManageGallery::class)->visibility($this->admin, true, $category->id, galleryRevision(), false);
    $this->get(route('public.media.show', $photo->media_asset_id))->assertNotFound();
    $this->get(route('public.gallery', ['kategoria' => $category->id]))->assertNotFound();
    expect($photo->fresh()->is_published)->toBeTrue();
});

test('moving a photo changes category without sharing or replacing its asset and appends to target', function () {
    $first = galleryCategory($this->admin, 'First', true);
    $second = galleryCategory($this->admin, 'Second', true);
    $photo = galleryPhoto($this->admin, $first, true);
    $existing = galleryPhoto($this->admin, $second, true);
    $assetId = $photo->media_asset_id;
    $this->actingAs($this->admin)->patch(route('admin.cms.gallery.images.update', $photo), ['gallery_category_id' => $second->id, 'is_published' => true, 'revision' => galleryRevision(), 'current_media_id' => $assetId, 'alt_text' => 'Updated alt'])->assertRedirect()->assertSessionHasNoErrors();
    expect($photo->fresh()->gallery_category_id)->toBe($second->id)->and($photo->fresh()->sort_order)->toBeGreaterThan($existing->sort_order)->and($photo->fresh()->media_asset_id)->toBe($assetId)
        ->and($photo->fresh()->media->owner_id)->toBe($photo->id)->and($photo->fresh()->media->alt_text)->toBe('Updated alt')->and(MediaAsset::count())->toBe(2)->and(MediaFileDeletion::count())->toBe(0);
    $this->get(route('public.gallery', ['kategoria' => $first->id]))->assertInertia(fn (Assert $page) => $page->where('images.total', 0));
    $this->get(route('public.gallery', ['kategoria' => $second->id]))->assertInertia(fn (Assert $page) => $page->where('images.data.1.id', $photo->id));
});

test('category and photo reorder are stable and include hidden elements while preserving category groups', function () {
    $first = galleryCategory($this->admin, 'First', true);
    $second = galleryCategory($this->admin, 'Second', true);
    $a = galleryPhoto($this->admin, $first, true);
    $hidden = galleryPhoto($this->admin, $first);
    $b = galleryPhoto($this->admin, $first, true);
    $other = galleryPhoto($this->admin, $second, true);
    app(ManageGallery::class)->move($this->admin, false, $b->id, galleryRevision(), 'up');
    expect(GalleryImage::where('gallery_category_id', $first->id)->orderBy('sort_order')->pluck('id')->all())->toBe([$a->id, $b->id, $hidden->id]);
    app(ManageGallery::class)->move($this->admin, true, $second->id, galleryRevision(), 'up');
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->where('categories.0.id', $second->id)->where('images.data.0.id', $other->id)->where('images.data.1.id', $a->id)->where('images.data.2.id', $b->id));
});

test('image replacement removes old reference and queues file deletion only after successful save', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $old = $photo->media;
    $this->actingAs($this->admin)->post(route('admin.cms.gallery.images.update', $photo), ['_method' => 'patch', 'gallery_category_id' => $category->id, 'revision' => galleryRevision(), 'current_media_id' => $old->id, 'is_published' => false, 'image' => galleryUpload()])->assertSessionHasNoErrors();
    expect($photo->fresh()->media_asset_id)->not->toBe($old->id)->and(MediaAsset::find($old->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    Storage::disk('local')->assertExists($old->path);
    Queue::assertPushed(DeleteCmsMediaFile::class, 1);
});

test('nonempty category deletion is blocked with files preserved and empty category deletion succeeds', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $revision = galleryRevision();
    $this->actingAs($this->admin)->delete(route('admin.cms.gallery.categories.destroy', $category), ['revision' => $revision])->assertSessionHasErrors('category');
    expect($photo->fresh())->not->toBeNull()->and(galleryRevision())->toBe($revision)->and(MediaFileDeletion::count())->toBe(0);
    $this->delete(route('admin.cms.gallery.images.destroy', $photo), ['revision' => $revision, 'current_media_id' => $photo->media_asset_id])->assertSessionHasNoErrors();
    expect($photo->fresh())->toBeNull()->and(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(1);
    $this->delete(route('admin.cms.gallery.categories.destroy', $category), ['revision' => galleryRevision(), 'kategoria' => $category->id])->assertRedirect()->assertSessionHasNoErrors();
    expect(GalleryCategory::count())->toBe(0);
});

test('stale gallery category photo and ordering submissions cannot replace current state', function (string $operation) {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $stale = galleryRevision();
    galleryCategory($this->admin, 'New category');
    $data = ['name' => 'Stale', 'description' => 'Stale', 'gallery_category_id' => $category->id, 'is_published' => true, 'revision' => $stale, 'current_media_id' => $photo->media_asset_id, 'direction' => 'up'];
    [$type,$action] = explode('.', $operation);
    $item = $type === 'categories' ? $category : $photo;
    $method = $action === 'destroy' ? 'delete' : 'patch';
    $this->actingAs($this->admin)->{$method}(route('admin.cms.gallery.'.$operation, $item), $data)->assertSessionHasErrors('revision');
    expect($category->fresh()->name)->toBe('Test category')->and($photo->fresh()->is_published)->toBeFalse();
})->with(['categories.update', 'categories.visibility', 'categories.move', 'categories.destroy', 'images.update', 'images.visibility', 'images.move', 'images.destroy']);

test('shared media route participates in gallery revision and stale image token is rejected', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $revision = galleryRevision();
    app(ManageCmsMedia::class)->replace($this->admin, MediaPlacement::Gallery, $photo->id, $photo->media_asset_id, galleryUpload(), null);
    expect(galleryRevision())->toBe($revision + 1);
    $this->actingAs($this->admin)->patch(route('admin.cms.gallery.images.update', $photo), ['gallery_category_id' => $category->id, 'revision' => galleryRevision(), 'current_media_id' => $photo->media_asset_id, 'is_published' => true])->assertSessionHasErrors('image');
});

test('missing category invalid direction and boundary moves do not alter gallery', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $revision = galleryRevision();
    $this->actingAs($this->admin)->get(route('admin.cms.gallery.index', ['kategoria' => 9999]))->assertNotFound();
    $this->get(route('admin.cms.gallery.images.edit', 99999))->assertNotFound();
    $this->patch(route('admin.cms.gallery.images.move', $photo), ['revision' => $revision, 'direction' => 'up'])->assertSessionHasErrors('direction');
    $this->patch(route('admin.cms.gallery.categories.move', $category), ['revision' => $revision, 'direction' => 'sideways'])->assertSessionHasErrors('direction');
    expect(galleryRevision())->toBe($revision);
});

test('admin image and category pagination keeps global movement flags across page boundaries', function () {
    $category = galleryCategory($this->admin, 'Many', true);
    foreach (range(1, 25) as $i) {
        galleryPhoto($this->admin, $category, true);
    }
    foreach (range(1, 12) as $i) {
        galleryCategory($this->admin, 'Category '.$i);
    }
    $this->actingAs($this->admin)->get(route('admin.cms.gallery.index', ['page' => 2, 'categories_page' => 2, 'kategoria' => $category->id]))->assertInertia(fn (Assert $page) => $page->has('images.data', 1)->where('images.data.0.position', 25)->where('images.data.0.canMoveUp', true)->where('images.data.0.canMoveDown', false)->has('categories.data', 1)->where('images.total', 25));
    $last = GalleryImage::orderByDesc('sort_order')->firstOrFail();
    app(ManageGallery::class)->move($this->admin, false, $last->id, galleryRevision(), 'up');
    expect(GalleryImage::orderBy('sort_order')->pluck('id')->all()[23])->toBe($last->id);
});

test('audit failure rolls image replacement and gallery revision back', function () {
    $category = galleryCategory($this->admin);
    $photo = galleryPhoto($this->admin, $category);
    $old = $photo->media;
    $revision = galleryRevision();
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Audit failure'));
    expect(fn () => app(ManageGallery::class)->saveImage($this->admin, $photo->id, ['gallery_category_id' => $category->id, 'is_published' => true, 'revision' => $revision, 'current_media_id' => $old->id, 'alt_text' => null], galleryUpload()))->toThrow(ValidationException::class);
    expect($photo->fresh()->media_asset_id)->toBe($old->id)->and(galleryRevision())->toBe($revision)->and($photo->fresh()->is_published)->toBeFalse();
    expect(MediaFileDeletion::sole()->path)->not->toBe($old->path);
    Storage::disk('local')->assertExists($old->path);
});

test('gallery business actions enforce admin permission independently of routes', function () {
    $customer = User::factory()->create();
    expect(fn () => galleryCategory($customer))->toThrow(AuthorizationException::class);
    expect(GalleryCategory::count())->toBe(0);
});

test('two administrators cannot concurrently create or reorder the same gallery revision', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locking is verified in the MySQL suite.');
    }
    $itemId = 0;
    if ($mode === 'move') {
        $category = galleryCategory($this->admin);
        galleryPhoto($this->admin, $category);
        $itemId = galleryPhoto($this->admin, $category)->id;
    }
    $revision = galleryRevision();
    $other = User::factory()->admin()->create();
    $barrier = tempnam(sys_get_temp_dir(), 'gallery-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
    $processes = [];
    try {
        foreach ([$this->admin, $other] as $index => $actor) {
            $worker = (string) ($index + 1);
            $process = new Process([PHP_BINARY, base_path('tests/Support/Cms/gallery-mutate.php'), $mode, (string) $actor->id, (string) $itemId, (string) $revision, $barrier, $worker], base_path(), $env, timeout: 15);
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
        expect($results)->toBe(['conflict', 'success'])->and(galleryRevision())->toBe($revision + 1);
        if ($mode === 'create') {
            expect(GalleryCategory::count())->toBe(1);
        } else {
            expect(GalleryImage::orderBy('sort_order')->pluck('id')->first())->toBe($itemId);
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
})->with(['create', 'move']);

test('new gallery models default to hidden while explicitly published images remain visible', function () {
    expect((new GalleryImage)->is_published)->toBeFalse();
    $category = galleryCategory($this->admin, 'Public', true);
    $photo = galleryPhoto($this->admin, $category, true);
    expect($photo->fresh()->is_published)->toBeTrue();
    $this->get(route('public.media.show', $photo->media_asset_id))->assertOk();
});

test('sequential multi upload returns fresh revisions and saves separately owned ordered photos', function () {
    $category = galleryCategory($this->admin);
    $revision = galleryRevision();
    $this->actingAs($this->admin);
    foreach (['Pierwsze zdjęcie', 'Drugie zdjęcie'] as $index => $alt) {
        $this->post(route('admin.cms.gallery.images.store'), [
            'gallery_category_id' => $category->id, 'revision' => $revision + $index,
            'current_media_id' => null, 'image' => galleryUpload(), 'alt_text' => $alt,
            'is_published' => false, 'continue_upload' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.cms.gallery.images.create', ['kategoria' => $category->id]));
        $this->get(route('admin.cms.gallery.images.create', ['kategoria' => $category->id]))
            ->assertInertia(fn (Assert $page) => $page->where('revision', $revision + $index + 1)->where('selectedCategory', $category->id));
    }
    $photos = GalleryImage::with('media')->orderBy('sort_order')->get();
    expect($photos)->toHaveCount(2)
        ->and($photos->pluck('sort_order')->all())->toBe([0, 1])
        ->and($photos->pluck('gallery_category_id')->unique()->all())->toBe([$category->id])
        ->and($photos->pluck('media_asset_id')->unique())->toHaveCount(2)
        ->and($photos->pluck('media.alt_text')->all())->toBe(['Pierwsze zdjęcie', 'Drugie zdjęcie'])
        ->and($photos->where('is_published', true))->toHaveCount(0);
});

test('invalid later upload preserves earlier success and can be retried with the same revision', function () {
    $category = galleryCategory($this->admin);
    $this->actingAs($this->admin);
    $data = ['gallery_category_id' => $category->id, 'revision' => galleryRevision(), 'current_media_id' => null,
        'is_published' => true, 'continue_upload' => true];
    $this->post(route('admin.cms.gallery.images.store'), [...$data, 'image' => galleryUpload()])->assertSessionHasNoErrors();
    $data['revision'] = galleryRevision();
    $this->post(route('admin.cms.gallery.images.store'), [...$data, 'image' => UploadedFile::fake()->createWithContent('invalid.png', 'invalid')])->assertSessionHasErrors('image');
    expect(GalleryImage::count())->toBe(1)->and(galleryRevision())->toBe($data['revision']);
    $this->post(route('admin.cms.gallery.images.store'), [...$data, 'image' => galleryUpload()])->assertSessionHasNoErrors();
    expect(GalleryImage::count())->toBe(2)->and(MediaAsset::count())->toBe(2)->and(galleryRevision())->toBe($data['revision'] + 1);
});
