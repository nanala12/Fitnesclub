<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageCmsMedia;
use App\Enums\MediaPlacement;
use App\Jobs\DeleteCmsMediaFile;
use App\Models\AuditLog;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\OfferTile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

function cmsUpload(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function cmsAsset(string $path = 'cms/old.png'): MediaAsset
{
    Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));

    return MediaAsset::forceCreate(['disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => 68]);
}
function cmsOwner(MediaPlacement $placement, User $admin, ?MediaAsset $asset = null): Model
{
    $data = match ($placement) {
        MediaPlacement::Offer => ['title' => 'Test tile'],
        MediaPlacement::Slide => ['title' => 'Test slide'],
        MediaPlacement::News => ['title' => 'Test news', 'slug' => 'test-news', 'body' => 'Test body'],
        MediaPlacement::Gallery => ['gallery_category_id' => GalleryCategory::forceCreate(['name' => 'Test'])->id],
        MediaPlacement::Regulation => ['version_number' => 1, 'body' => 'Test terms', 'created_by_user_id' => $admin->id],
    };
    if ($asset !== null) {
        $data[$placement->column()] = $asset->id;
    }

    return $placement->model()::forceCreate($data);
}
function cmsEndpoint(Model $owner, string $name = 'store'): string
{
    return route('admin.cms.media.'.$name, ['placement' => MediaPlacement::forModel($owner), 'owner' => $owner->getKey()]);
}

test('admin uploads an actual image with random private path and audit actor', function () {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin);
    $this->actingAs($admin)->from('/admin')->post(cmsEndpoint($tile), ['image' => cmsUpload('../../malicious.php'), 'current_media_id' => null, 'alt_text' => 'Test photo', 'disk' => 'public', 'owner_type' => 'news'])->assertRedirect('/admin')->assertSessionHasNoErrors();
    $asset = MediaAsset::sole();
    expect($asset->path)->toMatch('~^cms/[0-9a-f-]{36}\.png$~')->and($asset->disk)->toBe('local')->and($asset->mime_type)->toBe('image/png')
        ->and($asset->owner_type)->toBe('offer')->and($asset->owner_id)->toBe($tile->id)->and($asset->uploaded_by_user_id)->toBe($admin->id)
        ->and($tile->fresh()->media_asset_id)->toBe($asset->id)->and(MediaFileDeletion::count())->toBe(0);
    Storage::disk('local')->assertExists($asset->path);
    $audit = AuditLog::sole();
    expect($audit->action)->toBe('media.uploaded')->and($audit->actor_user_id)->toBe($admin->id)->and($audit->subject_type)->toBe('media_asset')->and($audit->metadata)->toBe(['changed_fields' => ['image']]);
    expect(json_encode($audit->getAttributes()))->not->toContain($asset->path)->not->toContain('malicious.php');
    $this->get(route('public.media.show', $asset))->assertNotFound();
    $this->get(route('admin.cms.media.show', $asset))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

test('unsafe spoofed empty oversized and excessive dimension uploads are rejected', function (string $kind) {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin);
    $file = match ($kind) {
        'svg' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
        'spoof' => UploadedFile::fake()->createWithContent('image.png', '<?php echo "unsafe";'),
        'empty' => UploadedFile::fake()->createWithContent('image.png', ''),
        'oversize' => UploadedFile::fake()->createWithContent('large.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII=').str_repeat('x', 5 * 1024 * 1024)),
        'dimensions' => UploadedFile::fake()->createWithContent('large.png', substr_replace(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='), pack('NN', 6001, 6001), 16, 8)),
    };
    $this->actingAs($admin)->post(cmsEndpoint($tile), ['image' => $file, 'current_media_id' => null])->assertSessionHasErrors('image');
    expect(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(0)->and($tile->fresh()->media_asset_id)->toBeNull();
    expect(Storage::disk('local')->allFiles('cms'))->toBe([]);
})->with(['svg', 'spoof', 'empty', 'oversize', 'dimensions']);

test('media endpoints reject unauthorized users including current suspended sessions', function (string $role) {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $asset);
    $user = match ($role) {
        'customer' => User::factory()->create(),
        'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]),
        'unverified' => User::factory()->admin()->unverified()->create(),
        default => null,
    };
    if ($user !== null) {
        $this->actingAs($user);
    }
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    $this->post(cmsEndpoint($tile), ['image' => cmsUpload(), 'current_media_id' => $asset->id])->assertStatus($status);
    if ($role === 'suspended') {
        $this->actingAs($user);
    }
    $this->delete(cmsEndpoint($tile, 'destroy'), ['current_media_id' => $asset->id])->assertStatus($status);
    if ($role === 'suspended') {
        $this->actingAs($user);
    }
    $this->get(route('admin.cms.media.show', $asset))->assertStatus($status);
    expect($tile->fresh()->media_asset_id)->toBe($asset->id)->and(MediaAsset::count())->toBe(1)->and(MediaFileDeletion::count())->toBe(0);
    Storage::disk('local')->assertExists($asset->path);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('replacement detaches old asset and defers actual deletion until commit', function () {
    $admin = User::factory()->admin()->create();
    $old = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $old);
    $new = cmsAsset('cms/new.png');
    DB::beginTransaction();
    $tile->forceFill(['media_asset_id' => $new->id])->save();
    expect(MediaAsset::find($old->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    Queue::assertNothingPushed();
    Storage::disk('local')->assertExists($old->path);
    DB::commit();
    Queue::assertPushed(DeleteCmsMediaFile::class, 1);
    $deletion = MediaFileDeletion::sole();
    (new DeleteCmsMediaFile($deletion->id))->handle();
    (new DeleteCmsMediaFile($deletion->id))->handle();
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($new->path);
    expect(MediaFileDeletion::count())->toBe(0)->and($tile->fresh()->media_asset_id)->toBe($new->id);
});

test('rollback preserves original reference asset and file and does not queue deletion', function () {
    $admin = User::factory()->admin()->create();
    $old = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $old);
    $new = cmsAsset('cms/new.png');
    DB::beginTransaction();
    $tile->forceFill(['media_asset_id' => $new->id])->save();
    DB::rollBack();
    expect($tile->fresh()->media_asset_id)->toBe($old->id)->and(MediaAsset::find($old->id))->not->toBeNull()->and(MediaFileDeletion::count())->toBe(0);
    Storage::disk('local')->assertExists($old->path);
    Queue::assertNothingPushed();
});

test('removing an image clears optional owners and removes required image records', function (MediaPlacement $placement) {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $owner = cmsOwner($placement, $admin, $asset);
    $this->actingAs($admin)->delete(cmsEndpoint($owner, 'destroy'), ['current_media_id' => $asset->id])->assertRedirect()->assertSessionHasNoErrors();
    expect(MediaAsset::find($asset->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
    if ($placement->requiresImage()) {
        expect($owner->fresh())->toBeNull();
    } else {
        expect($owner->fresh()->getAttribute($placement->column()))->toBeNull();
    }
    $this->get(route('public.media.show', $asset->id))->assertNotFound();
    expect(AuditLog::sole()->action)->toBe('media.removed');
})->with(MediaPlacement::cases());

test('one media asset cannot be attached to another owner even in another module', function (MediaPlacement $placement) {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $asset);
    expect(fn () => cmsOwner($placement, $admin, $asset))->toThrow(ValidationException::class);
    expect($asset->fresh()->owner_type)->toBe('offer')->and($asset->fresh()->owner_id)->toBe($tile->id);
})->with(MediaPlacement::cases());

test('published regulation images cannot be replaced removed or deleted with owner', function () {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $owner = cmsOwner(MediaPlacement::Regulation, $admin, $asset);
    $owner->forceFill(['published_at' => now()->subMinute()])->save();
    $this->actingAs($admin)->post(cmsEndpoint($owner), ['image' => cmsUpload(), 'current_media_id' => $asset->id])->assertSessionHasErrors('image');
    $this->delete(cmsEndpoint($owner, 'destroy'), ['current_media_id' => $asset->id])->assertSessionHasErrors('image');
    expect(fn () => $owner->delete())->toThrow(ValidationException::class);
    expect($owner->fresh()->media_asset_id)->toBe($asset->id)->and(MediaAsset::count())->toBe(1);
    Storage::disk('local')->assertExists($asset->path);
});

test('stale requests cannot replace or delete a newer image', function () {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $asset);
    $this->actingAs($admin)->post(cmsEndpoint($tile), ['image' => cmsUpload(), 'current_media_id' => null])->assertSessionHasErrors('image');
    $this->delete(cmsEndpoint($tile, 'destroy'), ['current_media_id' => $asset->id + 99])->assertSessionHasErrors('image');
    expect($tile->fresh()->media_asset_id)->toBe($asset->id)->and(MediaAsset::count())->toBe(1);
});

test('saving a stale owner without changing media does not resurrect a removed asset', function () {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, cmsAsset());
    $stale = $tile->fresh();
    $tile->forceFill(['media_asset_id' => null])->save();
    $stale->forceFill(['title' => 'Changed title'])->saveQuietly();
    expect($tile->fresh()->media_asset_id)->toBeNull();
});

test('failed upload transaction leaves durable cleanup but no content asset or audit', function () {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin);
    expect(fn () => app(ManageCmsMedia::class)->upload($admin, cmsUpload(), null, function (MediaAsset $asset) use ($tile): Model {
        $tile->forceFill(['media_asset_id' => $asset->id])->save();
        throw new RuntimeException('Simulated transaction failure');
    }))->toThrow(ValidationException::class);
    expect($tile->fresh()->media_asset_id)->toBeNull()->and(MediaAsset::count())->toBe(0)->and(AuditLog::count())->toBe(0);
    $pending = MediaFileDeletion::sole();
    Storage::disk('local')->assertExists($pending->path);
    Queue::assertPushed(DeleteCmsMediaFile::class);
    (new DeleteCmsMediaFile($pending->id))->handle();
    Storage::disk('local')->assertMissing($pending->path);
});

test('failed filesystem cleanup is logged retained and retried without touching content', function () {
    $pending = MediaFileDeletion::forceCreate(['disk' => 'local', 'path' => 'cms/failure.png', 'available_at' => now()]);
    $disk = Storage::disk('local');
    $disk->put($pending->path, 'test');
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($broken = Mockery::mock());
    $broken->shouldReceive('exists')->once()->with($pending->path)->andReturnTrue();
    $broken->shouldReceive('delete')->once()->with($pending->path)->andReturnFalse();
    Log::spy();
    expect(fn () => (new DeleteCmsMediaFile($pending->id))->handle())->toThrow(RuntimeException::class);
    expect($pending->fresh()->attempts)->toBe(1)->and($pending->fresh()->available_at->isFuture())->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('CMS media cleanup failed; retry pending.', ['deletion_id' => $pending->id, 'attempt' => 1]);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);
    $this->travel(6)->minutes();
    $this->artisan('cms:retry-media-cleanup')->assertSuccessful();
    Queue::assertPushed(DeleteCmsMediaFile::class, fn ($job) => $job->deletionId === $pending->id);
    (new DeleteCmsMediaFile($pending->id))->handle();
    expect(MediaFileDeletion::count())->toBe(0);
    $disk->assertMissing($pending->path);
});

test('cleanup protects unsafe paths and files still registered as live media', function () {
    $asset = cmsAsset();
    $pending = MediaFileDeletion::forceCreate(['disk' => 'local', 'path' => $asset->path, 'available_at' => now()]);
    (new DeleteCmsMediaFile($pending->id))->handle();
    Storage::disk('local')->assertExists($asset->path);
    expect($pending->fresh())->toBeNull();
    Storage::disk('local')->put('private.txt', 'secret');
    $unsafe = MediaFileDeletion::forceCreate(['disk' => 'local', 'path' => 'cms/../private.txt', 'available_at' => now()]);
    expect(fn () => (new DeleteCmsMediaFile($unsafe->id))->handle())->toThrow(RuntimeException::class);
    Storage::disk('local')->assertExists('private.txt');
    expect($unsafe->fresh()->attempts)->toBe(1);
});

test('scheduler recovers interrupted uploads after staging grace period', function () {
    $pending = MediaFileDeletion::forceCreate(['disk' => 'local', 'path' => 'cms/interrupted.png', 'available_at' => now()->addHour()]);
    Storage::disk('local')->put($pending->path, 'test');
    $this->artisan('cms:retry-media-cleanup')->assertSuccessful();
    Queue::assertNothingPushed();
    (new DeleteCmsMediaFile($pending->id))->handle();
    Storage::disk('local')->assertExists($pending->path);
    $this->travel(61)->minutes();
    $this->artisan('cms:retry-media-cleanup')->assertSuccessful();
    Queue::assertPushed(DeleteCmsMediaFile::class);
    (new DeleteCmsMediaFile($pending->id))->handle();
    Storage::disk('local')->assertMissing($pending->path);
});

test('model deletion and quiet deletion retire owned media', function () {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $owner = cmsOwner(MediaPlacement::Offer, $admin, $asset);
    $owner->deleteQuietly();
    expect($owner->fresh())->toBeNull()->and(MediaAsset::find($asset->id))->toBeNull()->and(MediaFileDeletion::count())->toBe(1);
});

test('bulk media mutations cannot bypass individual ownership and cleanup', function (string $operation) {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, cmsAsset());
    expect(fn () => match ($operation) {
        'update' => OfferTile::whereKey($tile->id)->update(['media_asset_id' => null]),
        'delete' => OfferTile::whereKey($tile->id)->delete(),
        'forceDelete' => OfferTile::whereKey($tile->id)->forceDelete(),
        'upsert' => OfferTile::upsert([['id' => $tile->id, 'media_asset_id' => null]], ['id']),
        'insert' => OfferTile::insert(['title' => 'Shared', 'media_asset_id' => $tile->media_asset_id]),
        'insertOrIgnore' => OfferTile::insertOrIgnore(['title' => 'Shared', 'media_asset_id' => $tile->media_asset_id]),
        'increment' => OfferTile::whereKey($tile->id)->increment('sort_order', 1, ['media_asset_id' => null]),
        'truncate' => OfferTile::truncate(),
    })->toThrow(LogicException::class);
    expect($tile->fresh()->media_asset_id)->not->toBeNull()->and(MediaAsset::count())->toBe(1);
})->with(['update', 'delete', 'forceDelete', 'upsert', 'insert', 'insertOrIgnore', 'increment', 'truncate']);

test('unknown media owners are not writable and required concurrency token is validated', function () {
    $admin = User::factory()->admin()->create();
    $tile = cmsOwner(MediaPlacement::Offer, $admin);
    $this->actingAs($admin)->post(cmsEndpoint($tile), ['image' => cmsUpload()])->assertSessionHasErrors('current_media_id');
    $this->post(route('admin.cms.media.store', ['placement' => 'offer', 'owner' => 999999]), ['image' => cmsUpload(), 'current_media_id' => null])->assertNotFound();
    $this->post('/admin/cms/media/arbitrary/1', ['image' => cmsUpload(), 'current_media_id' => null])->assertNotFound();
});

test('concurrent owners claiming the same asset allow exactly one winner', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locking is verified in the MySQL suite.');
    }
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $owners = [cmsOwner(MediaPlacement::Offer, $admin), cmsOwner(MediaPlacement::Offer, $admin)];
    $barrier = tempnam(sys_get_temp_dir(), 'cms-claim-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ($owners as $owner) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Media/claim.php'), (string) $owner->id, (string) $asset->id, $barrier], base_path(), $environment, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.'.$owners[0]->id) || ! file_exists($barrier.'.'.$owners[1]->id)) && microtime(true) < $deadline) {
            usleep(10000);
        }
        touch($barrier);
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);
        expect($outcomes)->toBe(['conflict', 'success'])->and(OfferTile::where('media_asset_id', $asset->id)->count())->toBe(1);
        expect($asset->fresh()->owner_id)->toBe(OfferTile::where('media_asset_id', $asset->id)->sole()->id);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.'.$owners[0]->id, $barrier.'.'.$owners[1]->id] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});

test('a new gallery record and its uploaded image are committed together', function () {
    $admin = User::factory()->admin()->create();
    $category = GalleryCategory::forceCreate(['name' => 'New gallery']);
    $asset = app(ManageCmsMedia::class)->upload($admin, cmsUpload(), 'Accessible text', fn (MediaAsset $asset): Model => GalleryImage::forceCreate(['gallery_category_id' => $category->id, 'media_asset_id' => $asset->id]));
    expect($asset->owner_type)->toBe('gallery')->and($asset->owner_id)->toBe(GalleryImage::sole()->id)->and(MediaFileDeletion::count())->toBe(0);
});

test('audit failure rolls replacement and retirement back while retaining failed upload cleanup', function () {
    $admin = User::factory()->admin()->create();
    $old = cmsAsset();
    $tile = cmsOwner(MediaPlacement::Offer, $admin, $old);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Audit failed'));
    expect(fn () => app(ManageCmsMedia::class)->replace($admin, MediaPlacement::Offer, $tile->id, $old->id, cmsUpload(), null))->toThrow(ValidationException::class);
    expect($tile->fresh()->media_asset_id)->toBe($old->id)->and(MediaAsset::count())->toBe(1)->and(MediaFileDeletion::count())->toBe(1)->and(AuditLog::count())->toBe(0);
    expect(MediaFileDeletion::sole()->path)->not->toBe($old->path);
    Storage::disk('local')->assertExists($old->path);
    Queue::assertPushed(DeleteCmsMediaFile::class, 1);
});

test('queue outage after commit leaves retirement recoverable and does not revert content', function () {
    $admin = User::factory()->admin()->create();
    $asset = cmsAsset();
    $owner = cmsOwner(MediaPlacement::Offer, $admin, $asset);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    Log::spy();
    app(ManageCmsMedia::class)->remove($admin, MediaPlacement::Offer, $owner->id, $asset->id);
    expect($owner->fresh()->media_asset_id)->toBeNull()->and(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(1)->and(AuditLog::sole()->action)->toBe('media.removed');
    Log::shouldHaveReceived('warning')->once()->with('CMS media cleanup dispatch failed.', ['deletion_id' => MediaFileDeletion::sole()->id]);
    Storage::disk('local')->assertExists($asset->path);
});

test('storage failure does not create a live asset or mutate its owner', function () {
    $admin = User::factory()->admin()->create();
    $owner = cmsOwner(MediaPlacement::Offer, $admin);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($broken = Mockery::mock());
    $broken->shouldReceive('putFileAs')->once()->andReturnFalse();
    expect(fn () => app(ManageCmsMedia::class)->replace($admin, MediaPlacement::Offer, $owner->id, null, cmsUpload(), null))->toThrow(ValidationException::class);
    expect($owner->fresh()->media_asset_id)->toBeNull()->and(MediaAsset::count())->toBe(0)->and(MediaFileDeletion::count())->toBe(1);
});
