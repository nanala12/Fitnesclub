<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ManageMembershipPlan;
use App\Enums\PlanKind;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\PlanPrice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function planData(array $overrides = []): array
{
    return ['name' => 'Karnet miesięczny', 'description' => 'Trening w klubie', 'kind' => 'time', 'price' => '155,50',
        'duration_months' => 1, 'included_entries' => null, 'is_active' => false, 'is_public' => false, 'is_featured' => false,
        'sort_order' => 0, 'revision' => 0, ...$overrides];
}

test('admin can open empty catalog create form and paginated plan list', function () {
    $this->actingAs($this->admin)->get(route('admin.plans.index'))->assertInertia(fn (Assert $page) => $page->component('admin/plans/Index')->where('plans.total', 0)->where('featuredCount', 0));
    $this->get(route('admin.plans.create'))->assertInertia(fn (Assert $page) => $page->component('admin/plans/Form')->where('plan', null));
    MembershipPlan::factory()->count(21)->create();
    $this->get(route('admin.plans.index'))->assertInertia(fn (Assert $page) => $page->where('plans.total', 21)->has('plans.data', 20));
    $this->get(route('admin.plans.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('plans.data', 1));
});

test('all plan endpoints reject unauthorized roles and existing suspended sessions', function (string $role) {
    $plan = MembershipPlan::factory()->create();
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]),
        'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    if ($user !== null) {
        $this->actingAs($user);
    }
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach ([['get', 'index'], ['get', 'create'], ['get', 'edit'], ['post', 'store'], ['patch', 'update'], ['delete', 'destroy']] as [$method, $action]) {
        if ($user !== null) {
            $this->actingAs($user);
        }
        $this->$method(route('admin.plans.'.$action, in_array($action, ['edit', 'update', 'destroy'], true) ? $plan : []), planData())->assertStatus($status);
    }
    expect(MembershipPlan::count())->toBe(1)->and(AuditLog::count())->toBe(0);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('plan actions repeat authorization without relying on the controller', function () {
    expect(fn () => app(ManageMembershipPlan::class)->save(User::factory()->employee()->create(), null, planData()))->toThrow(AuthorizationException::class);
});

test('admin creates updates and deletes an unused plan with atomic audit and index redirects', function () {
    $this->actingAs($this->admin)->post(route('admin.plans.store'), planData())->assertRedirect(route('admin.plans.index'));
    $plan = MembershipPlan::sole();
    expect($plan->price_grosz)->toBe(15550)->and($plan->kind)->toBe(PlanKind::Time)->and($plan->revision)->toBe(0)->and($plan->is_public)->toBeFalse();
    $this->get(route('admin.plans.edit', $plan))->assertInertia(fn (Assert $page) => $page->component('admin/plans/Form')->where('plan.price', '155,50')->where('plan.hasHistory', false)->missing('plan.terms')->missing('plan.orders'));
    $this->patch(route('admin.plans.update', $plan), planData(['name' => 'Nowa nazwa', 'price' => '169.99', 'is_active' => true, 'is_public' => true]))->assertRedirect(route('admin.plans.index'));
    expect($plan->fresh()->price_grosz)->toBe(16999)->and($plan->fresh()->revision)->toBe(1);
    $audit = AuditLog::where('action', 'plan.updated')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id)->and($audit->old_values['price_grosz'])->toBe(15550)->and($audit->new_values['price_grosz'])->toBe(16999)->and($audit->metadata['changed_fields'])->toContain('price_grosz');
    $this->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page->where('entries.data.0.subjectLabel', 'Plan karnetu #'.$plan->id)->where('entries.data.0.changes.0.before', '155,50 zł')->where('entries.data.0.changes.0.after', '169,99 zł'));
    $this->delete(route('admin.plans.destroy', $plan), ['revision' => 1, 'page' => 2])->assertRedirect(route('admin.plans.index', ['page' => 1]));
    expect(MembershipPlan::count())->toBe(0)->and(AuditLog::where('action', 'plan.deleted')->count())->toBe(1);
});

test('entry plans accept four or eight entries without monthly validity', function (int $entries) {
    $this->actingAs($this->admin)->post(route('admin.plans.store'), planData(['kind' => 'entries', 'duration_months' => null, 'included_entries' => $entries]))->assertSessionHasNoErrors();
    expect(MembershipPlan::sole()->included_entries)->toBe($entries)->and(MembershipPlan::sole()->duration_months)->toBeNull();
})->with([4, 8]);

test('incoherent configuration and forged server fields fail validation', function (array $override, string $field) {
    $this->actingAs($this->admin)->post(route('admin.plans.store'), planData($override))->assertSessionHasErrors($field);
    expect(MembershipPlan::count())->toBe(0)->and(AuditLog::count())->toBe(0);
})->with([
    [['name' => ' '], 'name'], [['kind' => 'unapproved'], 'kind'], [['duration_months' => null], 'duration_months'],
    [['duration_months' => 0], 'duration_months'], [['included_entries' => 8], 'included_entries'],
    [['kind' => 'entries', 'included_entries' => 8, 'duration_months' => 1], 'duration_months'],
    [['kind' => 'entries', 'included_entries' => null], 'included_entries'], [['kind' => 'entries', 'included_entries' => -1], 'included_entries'],
    [['price' => '-1'], 'price'], [['price' => '1.234'], 'price'], [['price' => '1e2'], 'price'], [['price' => 155.50], 'price'],
    [['price' => '92233720368547758,08'], 'price'], [['price_grosz' => 1], 'price_grosz'], [['currency' => 'EUR'], 'currency'],
    [['terms' => ['invented' => true]], 'terms'], [['revision' => -1], 'revision'], [['is_featured' => 'yes'], 'is_featured'],
    [['sort_order' => -1], 'sort_order'], [['description' => str_repeat('x', 5001)], 'description'],
]);

test('price parsing and formatting preserve exact integer grosz including maximum range', function () {
    expect(PlanPrice::parse('92233720368547758,07'))->toBe(PHP_INT_MAX)
        ->and(PlanPrice::decimal(PHP_INT_MAX))->toBe('92233720368547758,07')
        ->and(PlanPrice::label(PHP_INT_MAX))->toBe('92 233 720 368 547 758,07 zł')
        ->and(PlanPrice::parse('0'))->toBe(0)->and(PlanPrice::parse('1,2'))->toBe(120);
});

test('switching from time to entries clears catalog duration without rewriting purchase history', function () {
    $membership = Membership::factory()->create();
    $plan = $membership->order->plan;
    $plans = app(ManageMembershipPlan::class);
    $plans->save($this->admin, $plan->id, planData(['kind' => 'entries', 'included_entries' => 8]));
    expect($plan->fresh()->duration_months)->toBeNull()->and($membership->fresh()->plan_snapshot['duration_months'])->toBe(1);
});

test('public entry plans never expose monthly validity from earlier catalog records', function () {
    MembershipPlan::factory()->entries()->create(['duration_months' => 1, 'is_active' => true, 'is_public' => true]);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->where('plans.0.durationMonths', null)->where('plans.0.includedEntries', 8));
});

test('stale edits and deletion cannot overwrite the latest plan revision', function () {
    $plans = app(ManageMembershipPlan::class);
    $plan = $plans->save($this->admin, null, planData());
    $plans->save($this->admin, $plan->id, planData(['price' => '200']));
    $this->actingAs($this->admin)->patch(route('admin.plans.update', $plan), planData(['price' => '1']))->assertSessionHasErrors('revision');
    $this->delete(route('admin.plans.destroy', $plan), ['revision' => 0])->assertSessionHasErrors('revision');
    expect($plan->fresh()->price_grosz)->toBe(20000)->and(AuditLog::where('action', 'plan.updated')->count())->toBe(1);
});

test('a fourth featured flag is rejected even for a hidden plan while existing featured edits work', function () {
    MembershipPlan::factory()->count(3)->create(['is_featured' => true]);
    $this->actingAs($this->admin)->post(route('admin.plans.store'), planData(['is_featured' => true]))->assertSessionHasErrors('is_featured');
    $plan = MembershipPlan::firstOrFail();
    $this->patch(route('admin.plans.update', $plan), planData(['is_featured' => true, 'price' => '170']))->assertSessionHasNoErrors();
    expect(MembershipPlan::count())->toBe(3)->and($plan->fresh()->price_grosz)->toBe(17000);
});

test('catalog changes leave purchased snapshots unchanged and prevent plan deletion', function () {
    $membership = Membership::factory()->create();
    $order = $membership->order;
    $plan = $order->plan;
    $originalOrder = $order->fresh()->getRawOriginal();
    $originalPass = $membership->fresh()->getRawOriginal();
    $this->actingAs($this->admin)->patch(route('admin.plans.update', $plan), planData(['price' => '169', 'kind' => 'entries', 'included_entries' => 8, 'duration_months' => null]))->assertSessionHasNoErrors();
    $this->delete(route('admin.plans.destroy', $plan), ['revision' => 1])->assertSessionHasErrors('plan');
    expect($order->fresh()->getRawOriginal())->toBe($originalOrder)->and($membership->fresh()->getRawOriginal())->toBe($originalPass);
    $this->get(route('admin.plans.index'))->assertInertia(fn (Assert $page) => $page->where('plans.data.0.hasHistory', true));
});

test('public pricing and homepage share safe visible active plans in catalog order', function () {
    $first = MembershipPlan::factory()->create(['name' => 'Plan pierwszy', 'price_grosz' => 16999, 'is_active' => true, 'is_public' => true, 'is_featured' => true, 'sort_order' => 2, 'terms' => ['internal' => 'private']]);
    $second = MembershipPlan::factory()->entries(8)->create(['name' => 'Plan drugi', 'is_active' => true, 'is_public' => true, 'is_featured' => false, 'sort_order' => 3]);
    MembershipPlan::factory()->create(['name' => 'Ukryty', 'is_active' => true, 'is_featured' => true]);
    MembershipPlan::factory()->create(['name' => 'Niedostępny', 'is_public' => true, 'is_featured' => true]);
    $this->get(route('public.pricing'))->assertInertia(fn (Assert $page) => $page->has('plans', 2)->where('plans.0.id', $first->id)->where('plans.0.priceLabel', '169,99 zł')->where('plans.1.id', $second->id)->missing('plans.0.terms')->missing('plans.0.revision')->missing('plans.0.created_at'));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('featuredPlans', 1)->where('featuredPlans.0.id', $first->id));
    expect(MembershipPlan::publiclyAvailable()->whereKey($first)->exists())->toBeTrue()->and(MembershipPlan::publiclyAvailable()->count())->toBe(2);
});

test('audit failure rolls back a plan change and its revision', function () {
    $plan = MembershipPlan::factory()->create();
    $this->mock(RecordAudit::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Test failure'));
    expect(fn () => app(ManageMembershipPlan::class)->save($this->admin, $plan->id, planData(['price' => '200'])))->toThrow(RuntimeException::class);
    expect($plan->fresh()->price_grosz)->toBe(15000)->and($plan->fresh()->revision)->toBe(0);
});

test('two administrators competing for the third featured slot allow exactly one success', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real catalog row locks require MySQL.');
    }
    MembershipPlan::factory()->count(2)->create(['is_featured' => true]);
    $candidates = MembershipPlan::factory()->count(2)->create();
    $barrier = tempnam(sys_get_temp_dir(), 'plan-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/feature-plan.php'), (string) $this->admin->id, $mode === 'create' ? '0' : (string) $candidates[$worker]->id, $barrier, (string) $worker], base_path(), $environment, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
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
        expect($results)->toBe(['conflict', 'success'])->and(MembershipPlan::where('is_featured', true)->count())->toBe(3)->and(AuditLog::whereIn('action', ['plan.updated', 'plan.created'])->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.0', $barrier.'.1'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
})->with(['create', 'update']);
