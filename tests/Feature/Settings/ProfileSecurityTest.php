<?php

use App\Actions\UpdateUserProfile;
use App\Enums\UserRole;
use App\Jobs\SendProfileEmailVerification;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function profileData(User $user, array $overrides = []): array
{
    return array_replace(['first_name' => 'Anna Maria', 'last_name' => 'Kowalska-Nowak', 'email' => $user->email, 'phone' => $user->phone ?? '+48600700800'], $overrides);
}

test('each role can view and update only their own full profile', function (UserRole $role) {
    Queue::fake();
    $user = User::factory()->create(['role' => $role]);
    $other = User::factory()->create();
    $originalOther = $other->fresh()->getAttributes();
    $this->actingAs($user)->get(route('profile.edit', ['user_id' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->where('profile', [
            'first_name' => null, 'last_name' => null, 'email' => $user->email, 'phone' => $user->phone,
        ])->missing('profile.password')->missing('profile.role')->missing('profile.bootstrap_key'));
    $this->patch(route('profile.update'), profileData($user, ['phone' => '0048 (600) 700-800']))
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-updated');
    expect($user->fresh()->name)->toBe('Anna Maria Kowalska-Nowak')
        ->and($user->fresh()->first_name)->toBe('Anna Maria')
        ->and($user->fresh()->last_name)->toBe('Kowalska-Nowak')
        ->and($user->fresh()->phone)->toBe('+48600700800')
        ->and($user->fresh()->role)->toBe($role)
        ->and($other->fresh()->getAttributes())->toBe($originalOther);
    Queue::assertNothingPushed();
})->with(UserRole::cases());

test('email changes revoke verification for each role and old signed links cannot verify the new address', function (UserRole $role) {
    Queue::fake();
    $user = User::factory()->create(['role' => $role]);
    $oldUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->patch(route('profile.update'), profileData($user, ['email' => 'NEW@EXAMPLE.TEST']))
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-email-changed');
    $user->refresh();
    expect($user->email)->toBe('new@example.test')->and($user->email_verified_at)->toBeNull();
    Queue::assertPushed(SendProfileEmailVerification::class, fn ($job) => $job->userId === $user->id && $job->emailHash === sha1($user->email) && $job->afterCommit === true);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get($oldUrl)->assertForbidden();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->with(UserRole::cases());

test('changing email case only retains verification and does not dispatch email', function () {
    Queue::fake();
    $user = User::factory()->create();
    $verified = $user->email_verified_at->toISOString();
    $this->actingAs($user)->patch(route('profile.update'), profileData($user, ['email' => strtoupper($user->email)]))->assertSessionHasNoErrors();
    expect($user->fresh()->email_verified_at->toISOString())->toBe($verified);
    Queue::assertNothingPushed();
});

test('incomplete legacy accounts can supply genuine names and phone without guessing', function () {
    $user = User::factory()->create(['name' => 'Legacy combined name', 'phone' => null]);
    expect($user->first_name)->toBeNull()->and($user->last_name)->toBeNull();
    $this->actingAs($user)->patch(route('profile.update'), profileData($user))->assertSessionHasNoErrors();
    expect($user->fresh()->first_name)->toBe('Anna Maria')->and($user->fresh()->phone)->toBe('+48600700800');
});

test('invalid profile input cannot partially update data', function (string $field, mixed $value) {
    Queue::fake();
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();
    $this->actingAs($user)->patch(route('profile.update'), profileData($user, [$field => $value]))->assertSessionHasErrors($field);
    expect($user->fresh()->getAttributes())->toBe($before);
    Queue::assertNothingPushed();
})->with([
    ['first_name', ''], ['last_name', ''], ['first_name', str_repeat('a', 121)], ['last_name', ['invalid']],
    ['phone', null], ['phone', '600700800'], ['phone', '+0001234567'], ['phone', ['invalid']], ['email', 'invalid'],
]);

test('profile contacts are unique after normalization', function (string $field, string $value) {
    User::factory()->create(['email' => 'taken@example.test', 'phone' => '+48600700800']);
    $user = User::factory()->create();
    $this->actingAs($user)->patch(route('profile.update'), profileData($user, [$field => $value]))->assertSessionHasErrors($field);
})->with([['email', 'TAKEN@EXAMPLE.TEST'], ['phone', '0048 (600) 700-800']]);

test('a contact claimed after validation gives a field error and rolls back the entire profile update', function (string $field) {
    Queue::fake();
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();
    // Simulate another writer claiming the already validated value before the update.
    $validated = profileData($user, [$field => $field === 'email' ? 'claimed@example.test' : '+48600700800']);
    User::factory()->create([$field => $validated[$field]]);
    try {
        app(UpdateUserProfile::class)->handle($user, $validated);
        $this->fail('The database uniqueness constraint must reject the stale validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($user->fresh()->getAttributes())->toBe($before);
    Queue::assertNothingPushed();
})->with(['email', 'phone']);

test('verification delivery skips stale changed addresses and already verified or suspended accounts', function (string $state) {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $job = new SendProfileEmailVerification($user->id, sha1($user->email));
    if ($state === 'changed') {
        $user->update(['email' => 'changed@example.test']);
    } elseif ($state === 'verified') {
        $user->markEmailAsVerified();
    } elseif ($state === 'deleted') {
        $user->delete();
    } else {
        $user->forceFill(['suspended_at' => now()])->save();
    }
    $job->handle();
    Notification::assertNothingSent();
})->with(['changed', 'verified', 'deleted', 'suspended']);

test('verification job sends to the current unverified address', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    (new SendProfileEmailVerification($user->id, sha1($user->email)))->handle();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('each role changes only their own password with the correct current password', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $other = User::factory()->create();
    $otherPassword = $other->password;
    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'New-test!Password42', 'password_confirmation' => 'New-test!Password42',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'password-updated');
    expect(Hash::check('New-test!Password42', $user->fresh()->password))->toBeTrue()
        ->and($other->fresh()->password)->toBe($otherPassword);
})->with(UserRole::cases());

test('password and profile payloads reject protected access and bootstrap fields', function (string $field, mixed $value) {
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();
    $this->actingAs($user)->patch(route('profile.update'), profileData($user, [$field => $value]))->assertSessionHasErrors($field);
    $this->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'New-test!Password42', 'password_confirmation' => 'New-test!Password42', $field => $value,
    ])->assertSessionHasErrors($field);
    expect($user->fresh()->getAttributes())->toBe($before);
})->with([['role', 'admin'], ['is_active', true], ['suspended_at', '2026-11-13'], ['email_verified_at', '2026-11-13'], ['bootstrap_key', 'initial-admin'], ['user_id', 999], ['id', 999], ['permissions', ['admin']]]);

test('guests cannot update profiles or passwords', function () {
    $this->patch(route('profile.update'), [])->assertRedirect(route('login'));
    $this->put(route('user-password.update'), [])->assertRedirect(route('login'));
});

test('a query parameter cannot select the mutation target', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->patch(route('profile.update', ['user_id' => $other->id]), profileData($user))->assertSessionHasErrors('user_id');
    $this->put(route('user-password.update', ['user_id' => $other->id]), [
        'current_password' => 'password', 'password' => 'New-test!Password42', 'password_confirmation' => 'New-test!Password42',
    ])->assertSessionHasErrors('user_id');
    expect($other->fresh()->name)->toBe($other->name);
});

test('failed verification delivery does not roll back the saved profile or restore verification', function () {
    Queue::fake();
    $user = User::factory()->create();
    app(UpdateUserProfile::class)->handle($user, profileData($user, ['email' => 'changed@example.test']));
    $before = $user->fresh()->getAttributes();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic mail failure'));
    expect(fn () => (new SendProfileEmailVerification($user->id, sha1('changed@example.test')))->handle())->toThrow(RuntimeException::class, 'Synthetic mail failure');
    expect($user->fresh()->getAttributes())->toBe($before)->and($user->fresh()->email_verified_at)->toBeNull();
});
