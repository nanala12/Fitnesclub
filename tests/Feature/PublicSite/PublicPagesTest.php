<?php

use App\Models\ClassScheduleSlot;
use App\Models\ClassType;
use App\Models\ClubSetting;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use App\Models\MediaAsset;
use App\Models\NewsPost;
use App\Models\OfferTile;
use App\Models\OpeningHour;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function publicAsset(string $path = 'cms/test.png', array $overrides = []): MediaAsset
{
    Storage::fake('local');
    Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1n8AAAAASUVORK5CYII='));

    return MediaAsset::forceCreate(['disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => 68, 'alt_text' => 'Zdjęcie testowe', ...$overrides]);
}
function newsRecord(array $overrides = []): NewsPost
{
    return NewsPost::forceCreate(['title' => 'Wiadomość testowa', 'slug' => 'wiadomosc-testowa', 'body' => 'Treść wpisu', 'is_published' => true, 'published_at' => now()->subDay(), ...$overrides]);
}

test('all public pages are readable without an account and have real empty states', function (string $route, string $component) {
    $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('auth.user', null)->where('club', null)->where('openingHours', [])->has('seo.canonical'));
})->with([['home', 'Welcome'], ['public.offer', 'public/Offer'], ['public.pricing', 'public/Pricing'], ['public.news.index', 'public/News/Index'], ['public.gallery', 'public/Gallery'], ['public.contact', 'public/Contact'], ['public.schedule', 'public/Schedule'], ['public.regulations', 'public/Regulations']]);

test('demo contacts prices and legal text are not published from reference HTML', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('tiles', [])->where('slides', [])->where('featuredPlans', []));
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->where('plans', []));
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation', null));
    $this->get(route('public.contact'))->assertDontSee('magda@activefit.pl')->assertDontSee('693 388 508');
});

test('only published ordered tiles and slides are sent to the home page', function () {
    $image = publicAsset();
    OfferTile::forceCreate(['title' => 'Hidden', 'is_published' => false]);
    OfferTile::forceCreate(['title' => 'Second', 'short_description' => '<script>bad()</script>Opis', 'is_published' => true, 'sort_order' => 2, 'link_url' => 'javascript:alert(1)']);
    OfferTile::forceCreate(['title' => 'First', 'is_published' => true, 'sort_order' => 1]);
    HeroSlide::forceCreate(['title' => 'Published slide', 'media_asset_id' => $image->id, 'is_published' => true]);
    $hidden = publicAsset('cms/hidden.png');
    HeroSlide::forceCreate(['title' => 'Hidden slide', 'media_asset_id' => $hidden->id, 'is_published' => false]);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('tiles', 2)->where('tiles.0.title', 'First')->where('tiles.1.href', route('public.offer.show', OfferTile::where('title', 'Second')->sole()->id))->has('slides', 1)->where('slides.0.title', 'Published slide')->missing('slides.0.image.path')->missing('slides.0.image.disk'));
});

test('draft future and unscheduled news are not publicly accessible', function (string $state) {
    $post = newsRecord(match ($state) {
        'draft' => ['is_published' => false],'future' => ['published_at' => now()->addDay()],'unscheduled' => ['published_at' => null]
    });
    $this->get(route('public.news.index'))->assertInertia(fn (Assert $page) => $page->where('posts.total', 0));
    $this->get(route('public.news.show', $post->slug))->assertNotFound();
})->with(['draft', 'future', 'unscheduled']);

test('news detail sends sanitized HTML without executable content or editor data', function () {
    $author = User::factory()->admin()->create();
    $post = newsRecord(['title' => '<script>unsafe</script>', 'body' => '<p>Treść</p><img src=x onerror=alert(1)><a href="javascript:alert(1)">Link</a>', 'created_by_user_id' => $author->id]);
    $this->get(route('public.news.show', $post->slug))->assertOk()->assertInertia(fn (Assert $page) => $page->where('post.bodyHtml', '<p>Treść</p>Link')->missing('post.created_by_user_id')->missing('post.body')->missing('post.media_asset_id'));
});

test('news pagination and homepage teaser use only published posts', function () {
    foreach (range(1, 12) as $number) {
        newsRecord(['title' => 'Post '.$number, 'slug' => 'post-'.$number, 'published_at' => now()->subMinutes($number)]);
    }
    $this->get(route('public.news.index'))->assertInertia(fn (Assert $page) => $page->has('posts.data', 9)->where('posts.total', 12)->where('posts.data.0.title', 'Post 1'));
    $this->get(route('public.news.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('posts.data', 3));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('news', 3));
});

test('gallery hides unpublished categories and preserves category context in pagination', function () {
    $visible = GalleryCategory::forceCreate(['name' => 'Visible', 'is_published' => true]);
    $hidden = GalleryCategory::forceCreate(['name' => 'Hidden', 'is_published' => false]);
    GalleryImage::forceCreate(['is_published' => true, 'gallery_category_id' => $visible->id, 'media_asset_id' => publicAsset()->id]);
    GalleryImage::forceCreate(['is_published' => true, 'gallery_category_id' => $hidden->id, 'media_asset_id' => publicAsset('cms/private.png')->id]);
    $this->get(route('public.gallery', ['kategoria' => $visible->id]))->assertInertia(fn (Assert $page) => $page->has('categories', 1)->where('images.total', 1)->where('selectedCategory', $visible->id));
    $this->get(route('public.gallery', ['kategoria' => $hidden->id]))->assertNotFound();
    $this->get(route('public.gallery', ['kategoria' => 999]))->assertNotFound();
});

test('only the latest already published regulation version is public', function () {
    $author = User::factory()->admin()->create();
    foreach ([[1, now()->subDays(2)], [2, now()->subDay()], [3, now()->addDay()], [4, null]] as [$number,$date]) {
        RegulationVersion::forceCreate(['version_number' => $number, 'body' => 'Version '.$number, 'published_at' => $date, 'created_by_user_id' => $author->id]);
    }
    $this->get(route('public.regulations'))->assertInertia(fn (Assert $page) => $page->where('regulation.versionNumber', 2)->where('regulation.bodyText', 'Version 2')->missing('regulation.created_by_user_id'));
});

test('contact fields come from the singleton and unsafe links are omitted', function () {
    ClubSetting::forceCreate(['id' => 1, 'club_name' => 'Klub Testowy', 'contact_phone' => '+48 600 700 800', 'contact_email' => 'club@example.test', 'map_url' => 'javascript:alert(1)']);
    OpeningHour::forceCreate(['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '20:00']);
    OpeningHour::forceCreate(['weekday' => 7, 'is_closed' => true]);
    $this->get(route('public.contact'))->assertInertia(fn (Assert $page) => $page->where('club.name', 'Klub Testowy')->where('club.phoneHref', 'tel:+48600700800')->where('club.mapUrl', null)->has('openingHours', 2)->where('openingHours.0.opensAt', '08:00')->where('openingHours.1.closed', true));
});

test('schedule excludes hidden classes or slots and uses stored local times', function () {
    $type = ClassType::forceCreate(['name' => 'Testowe zajęcia', 'is_published' => true]);
    $hidden = ClassType::forceCreate(['name' => 'Hidden', 'is_published' => false]);
    foreach ([[$type->id, true], [$type->id, false], [$hidden->id, true]] as [$id,$published]) {
        ClassScheduleSlot::forceCreate(['class_type_id' => $id, 'weekday' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'is_published' => $published]);
    }
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 1)->where('slots.0.dayLabel', 'Wtorek')->where('slots.0.startsAt', '18:00')->missing('slots.0.is_published'));
});

test('public images require a current published owner and verified image MIME', function () {
    $asset = publicAsset();
    $this->get(route('public.media.show', $asset))->assertNotFound();
    $tile = OfferTile::forceCreate(['title' => 'Tile', 'media_asset_id' => $asset->id, 'is_published' => true]);
    $this->get(route('public.media.show', $asset))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $tile->forceFill(['is_published' => false])->save();
    $this->get(route('public.media.show', $asset))->assertNotFound();
});

test('media path disk MIME and missing file cannot expose private content', function (string $state) {
    $asset = publicAsset();
    OfferTile::forceCreate(['title' => 'Tile', 'media_asset_id' => $asset->id, 'is_published' => true]);
    match ($state) {
        'path' => $asset->forceFill(['path' => '../secret.txt'])->save(),
        'disk' => $asset->forceFill(['disk' => 'public'])->save(),
        'mime' => $asset->forceFill(['mime_type' => 'image/svg+xml'])->save(),
        'spoof' => Storage::disk('local')->put($asset->path, '<script>alert(1)</script>'),
        'missing' => Storage::disk('local')->delete($asset->path),
    };
    $this->get(route('public.media.show', $asset))->assertNotFound();
})->with(['path', 'disk', 'mime', 'spoof', 'missing']);

test('initial HTML includes escaped public SEO without requiring javascript', function () {
    config(['app.url' => 'https://activefit.example']);
    $response = $this->get(route('public.contact'));
    $response->assertSee('<title data-inertia="">Kontakt - ', false)->assertSee('rel="canonical" href="https://activefit.example/kontakt"', false)->assertSee('property="og:title"', false);
    newsRecord(['title' => '<script>alert(1)</script>']);
    $this->get(route('public.news.show', 'wiadomosc-testowa'))->assertDontSee('<title data-inertia=""><script>', false);
});

test('offer media are eager loaded rather than queried once per tile', function () {
    foreach (range(1, 12) as $index) {
        OfferTile::forceCreate(['title' => 'Tile '.$index, 'media_asset_id' => publicAsset('cms/tile-'.$index.'.png')->id, 'is_published' => true]);
    }
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/select .*from ["`]?media_assets["`]?/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
    $this->get(route('public.offer'))->assertOk();
    expect($queries)->toHaveCount(1);
});

test('news and gallery images lose public access when their parent is hidden', function (string $owner) {
    $asset = publicAsset();
    if ($owner === 'news') {
        $post = newsRecord(['cover_media_id' => $asset->id]);
    } else {
        $category = GalleryCategory::forceCreate(['name' => 'Category', 'is_published' => true]);
        GalleryImage::forceCreate(['is_published' => true, 'gallery_category_id' => $category->id, 'media_asset_id' => $asset->id]);
    }
    $this->get(route('public.media.show', $asset))->assertOk();
    if ($owner === 'news') {
        $post->forceFill(['is_published' => false])->save();
    } else {
        $category->forceFill(['is_published' => false])->save();
    }
    $this->get(route('public.media.show', $asset))->assertNotFound();
})->with(['news', 'gallery']);

test('offer cards link to their own detail and send a short excerpt while detail keeps the full text', function () {
    $text = str_repeat('Pełny opis oferty. ', 25);
    $tile = OfferTile::forceCreate(['title' => 'Oferta testowa', 'short_description' => $text, 'is_published' => true, 'link_url' => '/cennik']);
    $this->get(route('public.offer'))->assertInertia(fn (Assert $page) => $page->where('tiles.0.href', route('public.offer.show', $tile->id))->where('tiles.0.description', Str::limit(trim($text), 180, '…')));
    $this->get(route('public.offer.show', $tile->id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/OfferShow')->where('offer.description', trim($text))->where('offer.nextHref', '/cennik')->where('offer.nextLabel', 'Sprawdź cennik')->missing('offer.is_published')->missing('offer.short_description')->missing('offer.media_asset_id')->has('seo.canonical'));
});

test('hidden missing and subsequently unpublished offer details cannot be opened', function () {
    $tile = OfferTile::forceCreate(['title' => 'Hidden', 'is_published' => false]);
    $this->get(route('public.offer.show', $tile->id))->assertNotFound();
    $this->get(route('public.offer.show', 999999))->assertNotFound();
    $tile->forceFill(['is_published' => true])->save();
    $this->get(route('public.offer.show', $tile->id))->assertOk();
    $tile->forceFill(['is_published' => false])->save();
    $this->get(route('public.offer.show', $tile->id))->assertNotFound();
});

test('offer detail uses safe image DTO plain text and escaped canonical SEO', function () {
    config(['app.url' => 'https://club.example']);
    $tile = OfferTile::forceCreate(['title' => '<script>Title</script>', 'short_description' => '<p>Opis</p><img src=x onerror=alert(1)>', 'media_asset_id' => publicAsset()->id, 'is_published' => true, 'link_url' => 'javascript:alert(1)']);
    $this->get(route('public.offer.show', $tile->id))->assertInertia(fn (Assert $page) => $page->where('offer.description', 'Opis')->where('offer.nextHref', null)->where('seo.canonical', 'https://club.example/oferta/'.$tile->id)->missing('offer.image.path')->missing('offer.image.disk'));
    $this->get(route('public.offer.show', $tile->id))->assertDontSee('<title data-inertia=""><script>', false);
});
