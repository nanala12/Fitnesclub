<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManagePriceList;
use App\Models\AuditLog;
use App\Models\PriceListCategory;
use App\Models\PriceListService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->category = PriceListCategory::where('key', 'therapy')->sole();
    $this->service = app(ManagePriceList::class)->save($this->admin, 'service', null, ['name' => 'Usługa testowa', 'category' => 'therapy', 'price' => '120,50', 'is_published' => false, 'sort_order' => 0, 'revision' => 0]);
});

function pricingVisibilityRoute(string $type, int $id): string
{
    return route('admin.pricing.visibility', ['type' => $type, 'item' => $id]);
}

test('index visibility buttons change only publication and preserve both pagination positions with audit', function (string $type) {
    $item = $type === 'kategorie' ? $this->category : $this->service;
    $visible = ! $item->is_published;
    $before = $item->getAttributes();
    $this->actingAs($this->admin)->patch(pricingVisibilityRoute($type, $item->id), ['revision' => 0, 'is_published' => $visible, 'page' => 2, 'category_page' => 3, 'name' => 'Forged change', 'price' => '1'])
        ->assertRedirect(route('admin.pricing.index', ['page' => 2, 'category_page' => 3]));
    $item->refresh();
    expect($item->is_published)->toBe($visible)->and($item->revision)->toBe(1)->and($item->name)->toBe($before['name']);
    if ($item instanceof PriceListService) {
        expect($item->price_grosz)->toBe(12050);
    }
    $audit = AuditLog::where('action', $type === 'kategorie' ? 'price_category.updated' : 'price_service.updated')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id)->and($audit->metadata['changed_fields'])->toBe(['is_published']);
    $this->patch(pricingVisibilityRoute($type, $item->id), ['revision' => 1, 'is_published' => ! $visible])->assertRedirect(route('admin.pricing.index'));
    expect($item->fresh()->is_published)->toBe(! $visible);
})->with(['kategorie', 'uslugi']);

test('visibility route rejects non administrators including suspended sessions', function (string $role, string $type) {
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    if ($actor !== null) {
        $this->actingAs($actor);
    }
    $item = $type === 'kategorie' ? $this->category : $this->service;
    $this->patch(pricingVisibilityRoute($type, $item->id), ['revision' => 0, 'is_published' => ! $item->is_published])->assertStatus(in_array($role, ['guest', 'unverified'], true) ? 302 : 403);
    expect($item->fresh()->revision)->toBe(0);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified'])->with(['kategorie', 'uslugi']);

test('visibility requires revision and boolean publication values', function (array $data, string $field) {
    $this->actingAs($this->admin)->patch(pricingVisibilityRoute('uslugi', $this->service->id), $data)->assertSessionHasErrors($field);
    expect($this->service->fresh()->is_published)->toBeFalse();
})->with([
    [['is_published' => true], 'revision'], [['revision' => 0], 'is_published'],
    [['revision' => 0, 'is_published' => 'yes'], 'is_published'], [['revision' => 0, 'is_published' => true, 'category_page' => 0], 'category_page'],
]);

test('stale visibility writes cannot overwrite a newer publication state', function () {
    $action = app(ManagePriceList::class);
    $action->visibility($this->admin, 'service', $this->service->id, 0, true);
    $this->actingAs($this->admin)->patch(pricingVisibilityRoute('uslugi', $this->service->id), ['revision' => 0, 'is_published' => false])->assertSessionHasErrors('revision');
    expect($this->service->fresh()->is_published)->toBeTrue()->and($this->service->fresh()->revision)->toBe(1);
});

test('enabled service under hidden category stays private and index explains the category state', function () {
    $action = app(ManagePriceList::class);
    $action->visibility($this->admin, 'category', $this->category->id, 0, false);
    $action->visibility($this->admin, 'service', $this->service->id, 0, true);
    $this->actingAs($this->admin)->get(route('admin.pricing.index'))->assertInertia(fn (Assert $page) => $page->where('services.data.0.is_published', true)->where('services.data.0.categoryPublished', false));
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('priceCategories', 0));
    $action->visibility($this->admin, 'category', $this->category->id, 1, true);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('priceCategories', 1)->where('priceCategories.0.items.0.name', 'Usługa testowa'));
});

test('visibility action independently authorizes and rolls back on audit failure', function () {
    expect(fn () => app(ManagePriceList::class)->visibility(User::factory()->employee()->create(), 'service', $this->service->id, 0, true))->toThrow(AuthorizationException::class);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(ManagePriceList::class)->visibility($this->admin, 'service', $this->service->id, 0, true))->toThrow(RuntimeException::class);
    expect($this->service->fresh()->is_published)->toBeFalse()->and($this->service->fresh()->revision)->toBe(0);
});
