<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManagePriceList;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\PriceListCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

function priceCategoryData(array $overrides = []): array
{
    return ['name' => 'Nowa kategoria', 'is_published' => false, 'sort_order' => 0, 'revision' => 0, ...$overrides];
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('administrator creates renames sorts and deletes a custom category with stable item references', function () {
    $this->actingAs($this->admin)->post(route('admin.pricing.store', 'kategorie'), priceCategoryData(['is_published' => true]))->assertRedirect(route('admin.pricing.index'));
    $category = PriceListCategory::where('name', 'Nowa kategoria')->sole();
    expect(strlen($category->key))->toBe(30)->and(PriceListCategory::count())->toBe(6);
    $service = app(ManagePriceList::class)->save($this->admin, 'service', null, ['name' => 'Usługa nowa', 'category' => $category->key, 'price' => '100', 'is_published' => true, 'sort_order' => 0, 'revision' => 0]);
    $key = $category->key;
    $this->patch(route('admin.pricing.update', ['type' => 'kategorie', 'item' => $category->id]), priceCategoryData(['name' => 'Zajęcia specjalne', 'is_published' => true, 'sort_order' => 99]))->assertRedirect(route('admin.pricing.index'));
    expect($category->fresh()->key)->toBe($key)->and($service->fresh()->category)->toBe($key)->and($category->fresh()->revision)->toBe(1);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('categories', 6)->where('categories.5.value', $key)->where('categories.5.label', 'Zajęcia specjalne'));
    $this->delete(route('admin.pricing.destroy', ['type' => 'kategorie', 'item' => $category->id]), ['revision' => 1])->assertSessionHasErrors('item');
    app(ManagePriceList::class)->delete($this->admin, 'service', $service->id, 0);
    $this->delete(route('admin.pricing.destroy', ['type' => 'kategorie', 'item' => $category->id]), ['revision' => 1])->assertRedirect(route('admin.pricing.index'));
    expect(PriceListCategory::whereKey($category->id)->exists())->toBeFalse()->and(AuditLog::where('action', 'price_category.created')->sole()->actor_user_id)->toBe($this->admin->id);
});

test('new hidden category is selectable in service editor but absent publicly', function () {
    $category = app(ManagePriceList::class)->save($this->admin, 'category', null, priceCategoryData());
    $this->actingAs($this->admin)->get(route('admin.pricing.create', 'uslugi'))->assertInertia(fn (Assert $page) => $page->has('categories', 6));
    $this->get(route('admin.pricing.create', 'kategorie'))->assertInertia(fn (Assert $page) => $page->component('admin/pricing/Form')->where('type', 'kategorie'));
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('categories', 5));
    expect($category->is_published)->toBeFalse();
});

test('category endpoints reject non administrators and suspended sessions', function (string $role) {
    $category = PriceListCategory::where('key', 'therapy')->sole();
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach ([['get', 'create'], ['get', 'edit'], ['post', 'store'], ['patch', 'update'], ['delete', 'destroy']] as [$method, $action]) {
        if ($actor !== null) {
            $this->actingAs($actor);
        }
        $params = in_array($action, ['create', 'store'], true) ? ['type' => 'kategorie'] : ['type' => 'kategorie', 'item' => $category->id];
        $this->$method(route('admin.pricing.'.$action, $params), priceCategoryData())->assertStatus($status);
    }
    expect(PriceListCategory::count())->toBe(5);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('category validation rejects blank names unknown references and forged immutable keys', function () {
    $this->actingAs($this->admin)->post(route('admin.pricing.store', 'kategorie'), priceCategoryData(['name' => ' ']))->assertSessionHasErrors('name');
    $this->post(route('admin.pricing.store', 'kategorie'), priceCategoryData(['key' => 'gym']))->assertSessionHasErrors('key');
    $this->post(route('admin.pricing.store', 'kategorie'), priceCategoryData(['sort_order' => -1]))->assertSessionHasErrors('sort_order');
    $this->post(route('admin.pricing.store', 'uslugi'), ['name' => 'Grupa', 'category' => 'missing', 'is_published' => true, 'sort_order' => 0, 'revision' => 0])->assertSessionHasErrors('category');
    expect(PriceListCategory::count())->toBe(5);
});

test('hiding a category hides its services and featured passes on both public pages', function () {
    $action = app(ManagePriceList::class);
    $action->save($this->admin, 'service', null, ['name' => 'Usługa', 'category' => 'therapy', 'price' => '150', 'is_published' => true, 'sort_order' => 0, 'revision' => 0]);
    MembershipPlan::factory()->create(['category' => 'therapy', 'is_active' => true, 'is_public' => true, 'is_featured' => true]);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('priceCategories', 1)->has('featuredPlans', 1));
    $category = PriceListCategory::where('key', 'therapy')->sole();
    $action->save($this->admin, 'category', $category->id, priceCategoryData());
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('categories', 4)->has('priceCategories', 0)->has('plans', 0)->has('featuredPlans', 0));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('featuredPlans', 0));
});

test('default gym category can be renamed or hidden but is retained for default plans', function () {
    MembershipPlan::factory()->create(['is_active' => true, 'is_public' => true]);
    $category = PriceListCategory::where('key', 'gym')->sole();
    $action = app(ManagePriceList::class);
    expect(fn () => $action->delete($this->admin, 'category', $category->id, 0))->toThrow(ValidationException::class);
    $action->save($this->admin, 'category', $category->id, priceCategoryData(['name' => 'Moja siłownia']));
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('plans', 0)->has('priceCategories', 0));
    expect($category->fresh()->key)->toBe('gym');
});

test('stale category edits and failed audit never replace newer changes', function () {
    $category = PriceListCategory::where('key', 'training')->sole();
    $action = app(ManagePriceList::class);
    $action->save($this->admin, 'category', $category->id, priceCategoryData(['name' => 'Nowa nazwa']));
    expect(fn () => $action->save($this->admin, 'category', $category->id, priceCategoryData()))->toThrow(ValidationException::class);
    expect(fn () => $action->delete($this->admin, 'category', $category->id, 0))->toThrow(ValidationException::class);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(ManagePriceList::class)->save($this->admin, 'category', $category->id, priceCategoryData(['revision' => 1])))->toThrow(RuntimeException::class);
    expect($category->fresh()->name)->toBe('Nowa nazwa')->and($category->fresh()->revision)->toBe(1);
});

test('category records retain stable keys when edited and do not rewrite plan terms', function () {
    $plan = MembershipPlan::factory()->create(['category' => 'therapy']);
    $category = PriceListCategory::where('key', 'therapy')->sole();
    app(ManagePriceList::class)->save($this->admin, 'category', $category->id, priceCategoryData(['name' => 'Nowa fizjoterapia', 'is_published' => true]));
    expect($plan->fresh()->category)->toBe('therapy')->and($plan->fresh()->priceCategory->name)->toBe('Nowa fizjoterapia');
});
