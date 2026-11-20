<?php

use App\Enums\MediaPlacement;
use App\Jobs\DeleteCmsMediaFile;
use App\Models\AuditLog;
use App\Models\ClassType;
use App\Models\ClubContentState;
use App\Models\GalleryCategory;
use App\Models\GalleryContentState;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use App\Models\HomepageContentList;
use App\Models\MediaAsset;
use App\Models\MediaFileDeletion;
use App\Models\NewsPost;
use App\Models\OfferTile;
use App\Models\RegulationContentState;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(DatabaseMigrations::class);
beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});
function integrationCmsImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('cms.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));
}
function integrationHomeData(string $section): array
{
    return ['title' => 'Test content', 'description' => 'Test description', 'link_url' => '/kontakt', 'is_published' => false, 'revision' => HomepageContentList::findOrFail($section)->revision,
        'current_media_id' => null, 'remove_image' => false, 'alt_text' => 'Test image', 'image' => integrationCmsImage()];
}
function integrationDrafts(TestCase $test): array
{
    foreach (['kafelki', 'karuzela'] as $section) {
        $test->post(route('admin.cms.homepage.store', ['section' => $section]), integrationHomeData($section))->assertSessionHasNoErrors()->assertRedirect(route('admin.cms.homepage.index', ['section' => $section]));
    }
    $test->post(route('admin.cms.news.store'), ['title' => 'News test', 'excerpt' => 'Test excerpt', 'body' => '<p>Test <strong>news</strong></p><script>unsafe()</script>', 'is_published' => false,
        'revision' => 0, 'current_media_id' => null, 'remove_image' => false, 'image' => integrationCmsImage(), 'alt_text' => 'Test news image'])->assertSessionHasNoErrors()->assertRedirect();
    $test->post(route('admin.cms.gallery.categories.store'), ['revision' => 0, 'name' => 'Test gallery', 'description' => 'Test category', 'is_published' => false])->assertSessionHasNoErrors();
    $category = GalleryCategory::sole();
    $test->post(route('admin.cms.gallery.images.store'), ['revision' => GalleryContentState::findOrFail(1)->revision, 'gallery_category_id' => $category->id, 'is_published' => false, 'current_media_id' => null, 'image' => integrationCmsImage()])->assertSessionHasNoErrors();
    $test->post(route('admin.cms.regulations.store'), ['revision' => 0, 'body' => '<p>Test <strong>regulations</strong></p>', 'current_media_id' => null, 'image' => integrationCmsImage()])->assertSessionHasNoErrors()->assertRedirect();

    return ['offer' => OfferTile::sole(), 'slide' => HeroSlide::sole(), 'news' => NewsPost::sole(), 'category' => $category, 'gallery' => GalleryImage::sole(), 'regulation' => RegulationVersion::sole()];
}

test('admin can publish and hide content across modules while public pages and images enforce visibility', function () {
    $items = integrationDrafts($this);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('tiles', 0)->has('slides', 0)->has('news', 0)->where('featuredPlans', []));
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->has('images.data', 0));
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation', null));
    foreach (MediaAsset::all() as $asset) {
        $this->get(route('public.media.show', $asset))->assertNotFound();
    }
    foreach (['kafelki' => $items['offer'], 'karuzela' => $items['slide']] as $section => $item) {
        $this->patch(route('admin.cms.homepage.visibility', ['section' => $section, 'item' => $item->id]), ['revision' => HomepageContentList::findOrFail($section)->revision, 'is_published' => true])->assertSessionHasNoErrors();
    }
    $this->patch(route('admin.cms.news.visibility', $items['news']), ['revision' => 0, 'is_published' => true])->assertSessionHasNoErrors();
    $this->patch(route('admin.cms.gallery.categories.visibility', $items['category']), ['revision' => GalleryContentState::findOrFail(1)->revision, 'is_published' => true])->assertSessionHasNoErrors();
    $this->patch(route('admin.cms.gallery.images.visibility', $items['gallery']), ['revision' => GalleryContentState::findOrFail(1)->revision, 'is_published' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.cms.regulations.publish', $items['regulation']), ['revision' => RegulationContentState::findOrFail(1)->revision])->assertSessionHasNoErrors();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('tiles', 1)->has('slides', 1)->has('news', 1)->where('featuredPlans', []));
    $this->get(route('public.news.show', $items['news']->slug))->assertInertia(fn (Assert $page) => $page->where('post.bodyHtml', '<p>Test <strong>news</strong></p>'));
    $this->get(route('public.gallery'))->assertInertia(fn (Assert $page) => $page->has('images.data', 1));
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation.id', $items['regulation']->id));
    foreach (MediaAsset::all() as $asset) {
        $this->get(route('public.media.show', $asset))->assertOk();
    }
    foreach (['kafelki' => $items['offer'], 'karuzela' => $items['slide']] as $section => $item) {
        $this->patch(route('admin.cms.homepage.visibility', ['section' => $section, 'item' => $item->id]), ['revision' => HomepageContentList::findOrFail($section)->revision, 'is_published' => false])->assertSessionHasNoErrors();
    }
    $this->patch(route('admin.cms.news.visibility', $items['news']), ['revision' => $items['news']->fresh()->revision, 'is_published' => false])->assertSessionHasNoErrors();
    $this->patch(route('admin.cms.gallery.categories.visibility', $items['category']), ['revision' => GalleryContentState::findOrFail(1)->revision, 'is_published' => false])->assertSessionHasNoErrors();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('tiles', 0)->has('slides', 0)->has('news', 0));
    foreach (MediaAsset::where('owner_type', '!=', MediaPlacement::Regulation->value)->get() as $asset) {
        $this->get(route('public.media.show', $asset))->assertNotFound();
    }
    $this->get(route('public.media.show', $items['regulation']->media_asset_id))->assertOk();
    $hours = array_map(fn (int $day): array => ['weekday' => $day, 'status' => $day === 7 ? 'closed' : 'unknown'], range(1, 7));
    $this->patch(route('admin.cms.contact.update'), ['revision' => ClubContentState::findOrFail(1)->revision, 'club_name' => 'Test club', 'contact_email' => 'club@example.test', 'hours' => $hours])->assertSessionHasNoErrors()->assertRedirect(route('admin.cms.contact.index'));
    $this->post(route('admin.cms.schedule.types.store'), ['revision' => ClubContentState::findOrFail(1)->revision, 'name' => 'Test class', 'is_published' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.cms.schedule.slots.store'), ['revision' => ClubContentState::findOrFail(1)->revision, 'class_type_id' => ClassType::sole()->id, 'weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '19:00', 'is_published' => true])->assertSessionHasNoErrors();
    $this->get(route('public.contact'))->assertInertia(fn (Assert $page) => $page->where('club.email', 'club@example.test')->where('openingHours.6.closed', true)->where('openingHours.6.opensAt', null));
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 1));
    $this->patch(route('admin.cms.schedule.visibility'), ['revision' => ClubContentState::findOrFail(1)->revision, 'is_schedule_visible' => false])->assertSessionHasNoErrors();
    $this->get(route('public.schedule'))->assertNotFound();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('showSchedule', false));
    $this->patch(route('admin.cms.schedule.visibility'), ['revision' => ClubContentState::findOrFail(1)->revision, 'is_schedule_visible' => true])->assertSessionHasNoErrors();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 1));
    $this->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page->where('entries.data.0.actor.id', $this->admin->id)->missing('entries.data.0.metadata'));
    expect(AuditLog::where('actor_user_id', '!=', $this->admin->id)->count())->toBe(0);
});

test('each content editor removes its own image after commit without deleting media from other modules', function (string $kind) {
    $items = integrationDrafts($this);
    $item = $items[$kind];
    $mediaId = $kind === 'news' ? $item->cover_media_id : $item->media_asset_id;
    $asset = MediaAsset::findOrFail($mediaId);
    $path = $asset->path;
    $response = match ($kind) {
        'offer', 'slide' => $this->delete(route('admin.cms.homepage.destroy', ['section' => $kind === 'offer' ? 'kafelki' : 'karuzela', 'item' => $item->id]), ['revision' => HomepageContentList::findOrFail($kind === 'offer' ? 'kafelki' : 'karuzela')->revision, 'current_media_id' => $mediaId]),
        'news' => $this->delete(route('admin.cms.news.destroy', $item), ['revision' => $item->revision, 'current_media_id' => $mediaId]),
        'gallery' => $this->delete(route('admin.cms.gallery.images.destroy', $item), ['revision' => GalleryContentState::findOrFail(1)->revision, 'current_media_id' => $mediaId]),
        default => $this->delete(route('admin.cms.regulations.destroy', $item), ['revision' => RegulationContentState::findOrFail(1)->revision]),
    };
    $response->assertSessionHasNoErrors()->assertRedirect();
    expect(MediaAsset::find($mediaId))->toBeNull()->and(MediaAsset::count())->toBe(4);
    Storage::disk('local')->assertExists($path);
    $deletion = MediaFileDeletion::where('path', $path)->sole();
    Queue::assertPushed(DeleteCmsMediaFile::class, fn ($job) => $job->deletionId === $deletion->id);
    (new DeleteCmsMediaFile($deletion->id))->handle();
    Storage::disk('local')->assertMissing($path);
    expect(MediaFileDeletion::find($deletion->id))->toBeNull();
    foreach (MediaAsset::all() as $live) {
        Storage::disk('local')->assertExists($live->path);
    }
    $this->get(route('public.media.show', $mediaId))->assertNotFound();
})->with(['offer', 'slide', 'news', 'gallery', 'regulation']);

test('employee and customer cannot open any CMS section', function (string $role) {
    $user = $role === 'employee' ? User::factory()->employee()->create() : User::factory()->create();
    $this->actingAs($user);
    foreach (['admin.cms.news.index', 'admin.cms.gallery.index', 'admin.cms.regulations.index', 'admin.cms.contact.index', 'admin.cms.schedule.index'] as $name) {
        $this->get(route($name))->assertForbidden();
    }
    foreach (['kafelki', 'karuzela'] as $section) {
        $this->get(route('admin.cms.homepage.index', ['section' => $section]))->assertForbidden();
    }
})->with(['employee', 'customer']);
