<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManagePriceList;
use App\Actions\Memberships\ManageMembershipPlan;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PriceListCategory;
use App\Models\PriceListService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function directServiceData(array $overrides = []): array
{
    return ['name' => 'Usługa testowa', 'category' => 'therapy', 'price' => '120,50', 'is_published' => false, 'sort_order' => 0, 'revision' => 0, ...$overrides];
}

test('pricing administration has only categories and services and group routes are removed', function () {
    $this->actingAs($this->admin)->get(route('admin.pricing.index'))->assertInertia(fn (Assert $page) => $page->component('admin/pricing/Index')->has('categories.data', 5)->where('services.total', 0)->missing('groups'));
    $this->get(route('admin.pricing.create', 'uslugi'))->assertInertia(fn (Assert $page) => $page->has('categories', 5)->missing('groups'));
    $this->get('/admin/cennik/grupy/nowa')->assertNotFound();
    $this->post('/admin/cennik/grupy', directServiceData())->assertNotFound();
});

test('direct service endpoints require active verified administrators', function (string $role) {
    $service = app(ManagePriceList::class)->save($this->admin, 'service', null, directServiceData());
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach ([['get', 'index'], ['get', 'create'], ['get', 'edit'], ['post', 'store'], ['patch', 'update'], ['delete', 'destroy']] as [$method, $action]) {
        if ($actor !== null) {
            $this->actingAs($actor);
        }
        $params = match ($action) {
            'index' => [], 'create', 'store' => ['type' => 'uslugi'], default => ['type' => 'uslugi', 'item' => $service->id]
        };
        $this->$method(route('admin.pricing.'.$action, $params), directServiceData())->assertStatus($status);
    }
    expect(PriceListService::count())->toBe(1);
})->with(['guest', 'employee', 'customer', 'suspended', 'unverified']);

test('administrator edits direct category and service price with audit and index redirects', function () {
    $this->actingAs($this->admin)->post(route('admin.pricing.store', 'uslugi'), directServiceData())->assertRedirect(route('admin.pricing.index'));
    $service = PriceListService::sole();
    $this->patch(route('admin.pricing.update', ['type' => 'uslugi', 'item' => $service->id]), directServiceData(['category' => 'care', 'price' => '145,99', 'is_published' => true]))->assertRedirect(route('admin.pricing.index'));
    expect($service->fresh()->category)->toBe('care')->and($service->fresh()->price_grosz)->toBe(14599)->and($service->fresh()->revision)->toBe(1);
    $audit = AuditLog::where('action', 'price_service.updated')->sole();
    expect($audit->old_values['price_grosz'])->toBe(12050)->and($audit->new_values['price_grosz'])->toBe(14599)->and($audit->actor_user_id)->toBe($this->admin->id);
    $this->delete(route('admin.pricing.destroy', ['type' => 'uslugi', 'item' => $service->id]), ['revision' => 1])->assertRedirect(route('admin.pricing.index'));
    expect(PriceListService::count())->toBe(0);
});

test('invalid direct services and legacy group payloads fail validation', function (array $override, string $field) {
    $this->actingAs($this->admin)->post(route('admin.pricing.store', 'uslugi'), directServiceData($override))->assertSessionHasErrors($field);
    expect(PriceListService::count())->toBe(0);
})->with([
    [['price' => '-1'], 'price'], [['price' => '1.234'], 'price'], [['price' => 1.5], 'price'], [['price_grosz' => 5], 'price_grosz'],
    [['category' => 'missing'], 'category'], [['category' => null], 'category'], [['sort_order' => -1], 'sort_order'], [['price_list_group_id' => 1], 'price_list_group_id'],
]);

test('public categories contain plans and services directly in their configured order with one price source', function () {
    $plan = MembershipPlan::factory()->create(['category' => 'therapy', 'is_active' => true, 'is_public' => true, 'is_featured' => true, 'sort_order' => 2]);
    $action = app(ManagePriceList::class);
    $action->save($this->admin, 'service', null, directServiceData(['is_published' => true, 'sort_order' => 1]));
    $action->save($this->admin, 'service', null, directServiceData(['name' => 'Ukryta usługa']));
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->missing('groups')->has('priceCategories', 1)->where('priceCategories.0.name', 'Fizjoterapia')->has('priceCategories.0.items', 2)->where('priceCategories.0.items.0.priceLabel', '120,50 zł')->where('priceCategories.0.items.1.priceLabel', '150,00 zł')->has('featuredPlans', 1)->missing('plans.0.groupId'));
    $plan->forceFill(['price_grosz' => 16999])->save();
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->where('priceCategories.0.items.1.priceLabel', '169,99 zł')->where('featuredPlans.0.priceLabel', '169,99 zł'));
    $category = PriceListCategory::where('key', 'therapy')->sole();
    $action->save($this->admin, 'category', $category->id, ['name' => 'Fizjoterapia', 'is_published' => false, 'sort_order' => 0, 'revision' => 0]);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('priceCategories', 0)->has('plans', 0)->has('featuredPlans', 0));
});

test('plan editor assigns category directly and rejects a removed group field', function () {
    $plan = MembershipPlan::factory()->create();
    $data = ['name' => 'Plan', 'description' => null, 'kind' => 'time', 'price' => '150', 'duration_months' => 1, 'included_entries' => null, 'is_active' => false, 'is_public' => false, 'is_featured' => false, 'sort_order' => 0, 'revision' => 0];
    app(ManageMembershipPlan::class)->save($this->admin, $plan->id, [...$data, 'category' => 'care']);
    expect($plan->fresh()->category)->toBe('care');
    if (DB::getDriverName() === 'mysql') {
        app(ManageMembershipPlan::class)->save($this->admin, $plan->id, [...$data, 'category' => 'CARE', 'revision' => 1]);
        expect($plan->fresh()->category)->toBe('care');
    }
    $this->actingAs($this->admin)->get(route('admin.plans.edit', $plan))->assertInertia(fn (Assert $page) => $page->where('plan.category', 'care')->has('categories', 5)->missing('groups')->missing('plan.price_list_group_id'));
    $this->patch(route('admin.plans.update', $plan), [...$data, 'revision' => 1, 'price_list_group_id' => 1])->assertSessionHasErrors('price_list_group_id');
});

test('service stale revisions authorization and audit rollback remain enforced', function () {
    $action = app(ManagePriceList::class);
    expect(fn () => $action->save(User::factory()->employee()->create(), 'service', null, directServiceData()))->toThrow(AuthorizationException::class);
    $service = $action->save($this->admin, 'service', null, directServiceData());
    $action->save($this->admin, 'service', $service->id, directServiceData(['name' => 'Nowa nazwa']));
    expect(fn () => $action->save($this->admin, 'service', $service->id, directServiceData()))->toThrow(ValidationException::class);
    expect(fn () => $action->delete($this->admin, 'service', $service->id, 0))->toThrow(ValidationException::class);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(ManagePriceList::class)->save($this->admin, 'service', $service->id, directServiceData(['revision' => 1])))->toThrow(RuntimeException::class);
    expect($service->fresh()->name)->toBe('Nowa nazwa')->and($service->fresh()->revision)->toBe(1);
});

test('direct category and money foreign key constraints hold for raw service writes', function () {
    $service = app(ManagePriceList::class)->save($this->admin, 'service', null, directServiceData());
    expect(fn () => DB::table('price_list_services')->where('id', $service->id)->update(['category' => 'missing']))->toThrow(QueryException::class);
    expect(fn () => DB::table('price_list_services')->where('id', $service->id)->update(['price_grosz' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('price_list_categories')->where('key', 'therapy')->delete())->toThrow(QueryException::class);
});

test('flattening migration preserves prices hidden state and purchased snapshots through reversal and reapplication', function () {
    $membership = Membership::factory()->create();
    $plan = $membership->order->plan;
    $snapshot = $membership->plan_snapshot;
    $migration = require database_path('migrations/2026_11_23_010000_assign_prices_directly_to_categories.php');
    $migration->down();
    $groupId = DB::table('price_list_groups')->insertGetId(['name' => 'Starsza ukryta grupa', 'category' => 'therapy', 'is_published' => false, 'sort_order' => 0, 'revision' => 0]);
    DB::table('membership_plans')->where('id', $plan->id)->update(['price_list_group_id' => $groupId, 'is_public' => true]);
    $serviceId = DB::table('price_list_services')->insertGetId(['price_list_group_id' => $groupId, 'name' => 'Dawna usługa', 'price_grosz' => 12999, 'is_published' => true, 'sort_order' => 7, 'revision' => 3, 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    expect($plan->fresh()->category)->toBe('therapy')->and($plan->fresh()->is_public)->toBeFalse()->and($plan->fresh()->price_grosz)->toBe(15000)
        ->and(PriceListService::findOrFail($serviceId)->category)->toBe('therapy')->and(PriceListService::findOrFail($serviceId)->is_published)->toBeFalse()
        ->and(PriceListService::findOrFail($serviceId)->price_grosz)->toBe(12999)->and($membership->fresh()->plan_snapshot)->toBe($snapshot)
        ->and(Schema::hasColumn('price_list_services', 'price_list_group_id'))->toBeFalse();
    expect(fn () => DB::table('membership_plans')->where('id', $plan->id)->update(['price_grosz' => -1]))->toThrow(QueryException::class);
    $migration->down();
    expect(DB::table('price_list_services')->where('id', $serviceId)->value('price_grosz'))->toBe(12999);
    $migration->up();
    expect(PriceListService::findOrFail($serviceId)->price_grosz)->toBe(12999)->and($membership->fresh()->plan_snapshot)->toBe($snapshot);
});
