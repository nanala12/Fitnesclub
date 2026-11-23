<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ManageMembershipPlan;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true]);
});

test('plan visibility changes only publication with audit and keeps index pagination', function () {
    $before = $this->plan->refresh()->getAttributes();
    $this->actingAs($this->admin)->patch(route('admin.plans.visibility', $this->plan), ['revision' => 0, 'is_public' => true, 'page' => 2, 'price' => '1', 'is_active' => false])
        ->assertRedirect(route('admin.plans.index', ['page' => 2]));
    $after = $this->plan->fresh()->getAttributes();
    foreach ($before as $key => $value) {
        if (! in_array($key, ['is_public', 'revision', 'updated_at'], true)) {
            expect($after[$key])->toBe($value);
        }
    }
    expect($this->plan->fresh()->is_public)->toBeTrue()->and($this->plan->fresh()->revision)->toBe(1);
    $audit = AuditLog::where('action', 'plan.updated')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id)->and($audit->metadata['changed_fields'])->toBe(['is_public']);
    $this->patch(route('admin.plans.visibility', $this->plan), ['revision' => 1, 'is_public' => false])->assertRedirect(route('admin.plans.index'));
    expect($this->plan->fresh()->is_public)->toBeFalse();
});

test('plan visibility rejects unauthorized and suspended users', function (string $role) {
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(),
        'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null,
    };
    if ($actor !== null) {
        $this->actingAs($actor);
    }
    $this->patch(route('admin.plans.visibility', $this->plan), ['revision' => 0, 'is_public' => true])->assertStatus(in_array($role, ['guest', 'unverified'], true) ? 302 : 403);
    expect($this->plan->fresh()->is_public)->toBeFalse()->and($this->plan->fresh()->revision)->toBe(0);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('plan visibility validates input', function (array $data, string $field) {
    $this->actingAs($this->admin)->patch(route('admin.plans.visibility', $this->plan), $data)->assertSessionHasErrors($field);
    expect($this->plan->fresh()->is_public)->toBeFalse();
})->with([
    [['is_public' => true], 'revision'], [['revision' => 0], 'is_public'],
    [['revision' => 0, 'is_public' => 'yes'], 'is_public'], [['revision' => 0, 'is_public' => true, 'page' => 0], 'page'],
]);

test('stale plan visibility cannot overwrite newer state', function () {
    app(ManageMembershipPlan::class)->visibility($this->admin, $this->plan->id, 0, true);
    $this->actingAs($this->admin)->patch(route('admin.plans.visibility', $this->plan), ['revision' => 0, 'is_public' => false])->assertSessionHasErrors('revision');
    expect($this->plan->fresh()->is_public)->toBeTrue()->and($this->plan->fresh()->revision)->toBe(1);
});

test('visibility respects plan availability and category publication', function () {
    $action = app(ManageMembershipPlan::class);
    $action->visibility($this->admin, $this->plan->id, 0, true);
    expect(MembershipPlan::publiclyAvailable()->whereKey($this->plan->id)->exists())->toBeTrue();
    $this->plan->priceCategory()->firstOrFail()->forceFill(['is_published' => false])->save();
    expect(MembershipPlan::publiclyAvailable()->whereKey($this->plan->id)->exists())->toBeFalse();
    $this->plan->priceCategory()->firstOrFail()->forceFill(['is_published' => true])->save();
    $this->plan->forceFill(['is_active' => false])->save();
    $action->visibility($this->admin, $this->plan->id, 1, true);
    expect($this->plan->fresh()->is_active)->toBeFalse()
        ->and(MembershipPlan::publiclyAvailable()->whereKey($this->plan->id)->exists())->toBeFalse();
});

test('plan visibility action authorizes independently and audit failure rolls back', function () {
    expect(fn () => app(ManageMembershipPlan::class)->visibility(User::factory()->employee()->create(), $this->plan->id, 0, true))->toThrow(AuthorizationException::class);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(ManageMembershipPlan::class)->visibility($this->admin, $this->plan->id, 0, true))->toThrow(RuntimeException::class);
    expect($this->plan->fresh()->is_public)->toBeFalse()->and($this->plan->fresh()->revision)->toBe(0);
});
