<?php

namespace App\Actions\PublicSite;

use App\Enums\PlanKind;
use App\Models\ClassScheduleSlot;
use App\Models\ClubSetting;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use App\Models\MediaAsset;
use App\Models\MembershipPlan;
use App\Models\NewsPost;
use App\Models\OfferTile;
use App\Models\OpeningHour;
use App\Models\PriceListCategory;
use App\Models\RegulationVersion;
use App\Support\PhoneNumber;
use App\Support\PlanPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PublicSiteContent
{
    /** @return array<int, array<string, mixed>> */
    public function plans(bool $featured = false): array
    {
        $query = MembershipPlan::query()->publiclyAvailable()->orderBy('sort_order')->orderBy('id');
        if ($featured) {
            $query->where('is_featured', true)->limit(3);
        }

        return $query->get(['id', 'name', 'description', 'kind', 'price_grosz', 'duration_months', 'included_entries', 'category', 'sort_order'])->map(fn (MembershipPlan $plan): array => [
            'category' => $plan->category, 'sortOrder' => $plan->sort_order,
            'id' => $plan->id, 'name' => $plan->name, 'description' => $plan->description, 'kind' => $plan->kind->value,
            'priceLabel' => PlanPrice::label($plan->price_grosz), 'durationMonths' => $plan->kind === PlanKind::Time ? $plan->duration_months : null, 'includedEntries' => $plan->included_entries,
        ])->all();
    }

    /** @param array<int,array<string,mixed>> $plans
     * @return list<array<string,mixed>>
     */
    public function priceCategories(array $plans): array
    {
        $categories = PriceListCategory::query()->where('is_published', true)
            ->with(['services' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();
        $result = [];
        foreach ($categories as $category) {
            $items = [];
            foreach ($plans as $plan) {
                if ($plan['category'] === $category->key) {
                    $items[] = ['id' => 'plan-'.$plan['id'], 'name' => $plan['name'], 'priceLabel' => $plan['priceLabel'], 'purchaseUrl' => route('checkout.create', ['plan' => $plan['id']], absolute: false), 'order' => $plan['sortOrder'], 'rank' => 0, 'sourceId' => $plan['id']];
                }
            }
            foreach ($category->services as $service) {
                $items[] = ['id' => 'service-'.$service->id, 'name' => $service->name, 'priceLabel' => PlanPrice::label($service->price_grosz), 'order' => $service->sort_order, 'rank' => 1, 'sourceId' => $service->id];
            }
            usort($items, fn (array $a, array $b): int => [$a['order'], $a['rank'], $a['sourceId']] <=> [$b['order'], $b['rank'], $b['sourceId']]);
            if ($items !== []) {
                $result[] = ['id' => $category->key, 'name' => $category->name, 'category' => $category->key, 'items' => array_map(fn (array $item): array => array_intersect_key($item, array_flip(['id', 'name', 'priceLabel', 'purchaseUrl'])), $items)];
            }
        }

        return $result;
    }

    public const string PUBLIC_LINK_PATTERN = '~^/(?:oferta|cennik|grafik|galeria|kontakt|regulamin|aktualnosci(?:/[a-z0-9-]+)?)(?:\#[a-z0-9_-]+)?$~D';

    public const array WEEKDAYS = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'];

    /** @return array<string, mixed>|null */
    public function club(): ?array
    {
        $club = ClubSetting::find(1);
        if ($club === null) {
            return null;
        }
        $phone = is_string($club->contact_phone) ? PhoneNumber::normalize($club->contact_phone) : '';

        return [
            'name' => $club->club_name, 'email' => filter_var($club->contact_email, FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => $club->contact_phone, 'phoneHref' => preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone) ? 'tel:'.$phone : null,
            'address' => $club->street_address, 'postalCode' => $club->postal_code, 'city' => $club->city,
            'mapUrl' => $this->httpsUrl($club->map_url),
        ];
    }

    /** @return array<string, mixed> */
    public function shared(string $page = 'home', NewsPost|OfferTile|null $post = null): array
    {
        $hours = OpeningHour::query()->whereBetween('weekday', [1, 7])->orderBy('weekday')->get()->map(fn (OpeningHour $row): array => [
            'weekday' => $row->weekday, 'label' => self::WEEKDAYS[$row->weekday], 'closed' => $row->is_closed,
            'opensAt' => $row->opens_at === null ? null : substr($row->opens_at, 0, 5),
            'closesAt' => $row->closes_at === null ? null : substr($row->closes_at, 0, 5),
        ])->all();

        return ['showSchedule' => $this->scheduleVisible(), 'club' => $this->club(), 'openingHours' => $hours, 'siteUrl' => rtrim(config('app.url'), '/').'/', 'seo' => $this->seo($page, $post)];
    }

    /** @return array<int, array<string, mixed>> */
    public function offers(): array
    {
        return OfferTile::query()->with('media')->where('is_published', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn (OfferTile $row): array => $this->offerCard($row))->all();
    }

    /** @return array<string, mixed> */
    public function offerCard(OfferTile $row): array
    {
        return [
            'id' => $row->id, 'title' => $row->title, 'description' => Str::limit($this->text($row->short_description), 180, '…'),
            'image' => $this->image($row->media), 'href' => route('public.offer.show', $row->id),
        ];
    }

    /** @return array<string, mixed> */
    public function offerDetail(OfferTile $row): array
    {
        $next = $this->publicLink($row->link_url);
        if (preg_match('~^/grafik(?:#|$)~D', $row->link_url ?? '') && ! $this->scheduleVisible()) {
            $next = route('public.offer');
        }

        return [...$this->offerCard($row), 'description' => $this->text($row->short_description),
            'nextHref' => in_array($next, ['/oferta', route('public.offer')], true) ? null : $next,
            'nextLabel' => match (true) {
                str_starts_with($next, '/cennik') => 'Sprawdź cennik',
                str_starts_with($next, '/grafik') => 'Sprawdź grafik',
                str_starts_with($next, '/kontakt') => 'Skontaktuj się z nami',
                str_starts_with($next, '/galeria') => 'Zobacz galerię',
                str_starts_with($next, '/regulamin') => 'Przeczytaj regulamin',
                default => 'Zobacz więcej',
            },
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function slides(): array
    {
        return HeroSlide::query()->with('media')->where('is_published', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn (HeroSlide $row): array => [
            'id' => $row->id, 'title' => $row->title, 'description' => $this->text($row->description), 'image' => $this->image($row->media),
        ])->all();
    }

    /** @return Builder<NewsPost> */
    public function publishedNews(): Builder
    {
        return NewsPost::query()->where('is_published', true)->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** @return array<string, mixed> */
    public function newsCard(NewsPost $post): array
    {
        return ['id' => $post->id, 'title' => $post->title, 'excerpt' => $this->text($post->excerpt),
            'href' => route('public.news.show', $post->slug), 'publishedAt' => $post->published_at?->toISOString(), 'image' => $this->image($post->media)];
    }

    /** @return Builder<RegulationVersion> */
    public function publishedRegulations(): Builder
    {
        return RegulationVersion::query()->whereNotNull('published_at')->where('published_at', '<=', now())->orderByDesc('version_number');
    }

    public function scheduleVisible(): bool
    {
        return (bool) (ClubSetting::query()->whereKey(1)->value('is_schedule_visible') ?? true);
    }

    /** @return array<int, array<string,mixed>> */
    public function schedule(): array
    {
        return ClassScheduleSlot::query()->with('classType')->where('is_published', true)->whereHas('classType', fn (Builder $query) => $query->where('is_published', true))
            ->whereBetween('weekday', [1, 7])->orderBy('weekday')->orderBy('starts_at')->orderBy('id')->get()->map(fn (ClassScheduleSlot $row): array => [
                'id' => $row->id, 'weekday' => $row->weekday, 'dayLabel' => self::WEEKDAYS[$row->weekday],
                'name' => $row->classType->name, 'description' => $this->text($row->classType->description),
                'startsAt' => substr($row->starts_at, 0, 5), 'endsAt' => substr($row->ends_at, 0, 5), 'instructor' => $row->instructor_name,
            ])->all();
    }

    /** @return array{id:int,src:string,alt:string}|null */
    public function image(?MediaAsset $media): ?array
    {
        if ($media === null || ! $this->safeAsset($media)) {
            return null;
        }

        return ['id' => $media->id, 'src' => route('public.media.show', $media->id), 'alt' => $media->alt_text ?? ''];
    }

    public function safeAsset(MediaAsset $media): bool
    {
        return $media->disk === 'local' && str_starts_with($media->path, 'cms/') && ! str_contains($media->path, '..')
            && preg_match('~^cms/[a-zA-Z0-9_/.-]+$~D', $media->path) === 1
            && in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true);
    }

    public function publiclyReferenced(MediaAsset $media): bool
    {
        return OfferTile::where('media_asset_id', $media->id)->where('is_published', true)->exists()
            || HeroSlide::where('media_asset_id', $media->id)->where('is_published', true)->exists()
            || $this->publishedNews()->where('cover_media_id', $media->id)->exists()
            || GalleryImage::where('media_asset_id', $media->id)->where('is_published', true)->whereIn('gallery_category_id', GalleryCategory::where('is_published', true)->select('id'))->exists()
            || $this->publishedRegulations()->value('media_asset_id') === $media->id;
    }

    public function text(?string $value): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '</div>', '<br>', '<br/>', '<br />'], "\n", $value ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return array{title:string,description:string,canonical:string} */
    private function seo(string $page, NewsPost|OfferTile|null $post): array
    {
        if ($page === 'news.show' && $post instanceof NewsPost) {
            return ['title' => $post->title, 'description' => $this->text($post->excerpt) ?: 'Informacje z klubu ActiveFit.', 'canonical' => rtrim(config('app.url'), '/').route('public.news.show', $post->slug, false)];
        }
        if ($page === 'offer.show' && $post instanceof OfferTile) {
            return ['title' => $post->title, 'description' => Str::limit($this->text($post->short_description), 160, '…') ?: 'Poznaj ofertę ActiveFit.',
                'canonical' => rtrim(config('app.url'), '/').route('public.offer.show', $post->id, false)];
        }
        [$title,$description,$path] = match ($page) {
            'offer' => ['Oferta', 'Poznaj formy aktywności dostępne w ActiveFit.', '/oferta'],
            'pricing' => ['Cennik', 'Informacje o aktualnej ofercie i cenach karnetów ActiveFit.', '/cennik'],
            'news' => ['Aktualności', 'Informacje i wydarzenia z życia klubu ActiveFit.', '/aktualnosci'],
            'gallery' => ['Galeria', 'Zobacz opublikowane zdjęcia klubu ActiveFit.', '/galeria'],
            'contact' => ['Kontakt', 'Dane kontaktowe, lokalizacja i godziny otwarcia ActiveFit.', '/kontakt'],
            'schedule' => ['Grafik zajęć', 'Sprawdź opublikowany tygodniowy grafik zajęć ActiveFit.', '/grafik'],
            'regulations' => ['Regulamin', 'Aktualny opublikowany regulamin klubu ActiveFit.', '/regulamin'],
            default => ['ActiveFit — ruch w Twoim rytmie', 'Poznaj ofertę ActiveFit, sprawdź aktualności, grafik i informacje o klubie.', '/'],
        };

        return ['title' => $title, 'description' => $description, 'canonical' => rtrim(config('app.url'), '/').$path];
    }

    private function httpsUrl(?string $url): ?string
    {
        return $url !== null && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null ? $url : null;
    }

    private function publicLink(?string $url): string
    {
        return $url !== null && preg_match(self::PUBLIC_LINK_PATTERN, $url)
            ? $url : route('public.offer');
    }
}
