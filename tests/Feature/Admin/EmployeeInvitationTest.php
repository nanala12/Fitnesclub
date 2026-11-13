<?php

use App\Jobs\SendEmployeeInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function pendingEmployee(): User
{
    return User::factory()->employee()->unverified()->create(['staff_invited_at' => now()]);
}

function acceptInvitation(User $employee, string $token): array
{
    return ['token' => $token, 'email' => $employee->email, 'password' => 'Staff-only!Password42', 'password_confirmation' => 'Staff-only!Password42'];
}

test('invitation delivers a setup link with a hashed broker token and no password', function () {
    Notification::fake();
    $employee = pendingEmployee();
    (new SendEmployeeInvitation($employee->id, sha1($employee->email)))->handle();
    Notification::assertSentTo($employee, EmployeeInvitation::class, function (EmployeeInvitation $notification) use ($employee): bool {
        $stored = DB::table('password_reset_tokens')->where('email', $employee->email)->value('token');
        expect(Hash::check($notification->token, $stored))->toBeTrue();
        $mail = $notification->toMail($employee);
        expect($mail->subject)->toBe('Zaproszenie do zespołu ActiveFit')->and($mail->actionUrl)->toContain('/reset-password/', urlencode($employee->email))
            ->and(json_encode($mail->toArray()))->not->toContain($employee->password, 'password', 'Staff-only!');

        return true;
    });
});

test('invitation acceptance verifies the address sets a password and cannot be replayed', function () {
    $employee = pendingEmployee();
    $token = Password::createToken($employee);
    $this->post(route('password.update'), acceptInvitation($employee, $token))->assertSessionHasNoErrors()->assertRedirect(route('login'));
    expect($employee->fresh()->hasPendingStaffInvitation())->toBeFalse()->and($employee->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('Staff-only!Password42', $employee->fresh()->password))->toBeTrue();
    $password = $employee->fresh()->password;
    $this->post(route('password.update'), acceptInvitation($employee, $token))->assertSessionHasErrors('email');
    expect($employee->fresh()->password)->toBe($password);
    $this->post(route('login.store'), ['email' => $employee->email, 'password' => 'Staff-only!Password42'])->assertSessionHasNoErrors();
    $this->get(route('staff.dashboard'))->assertOk();
});

test('expired incorrect or foreign invitation tokens cannot set a password', function (string $kind) {
    $employee = pendingEmployee();
    $password = $employee->password;
    $token = Password::createToken($employee);
    if ($kind === 'expired') {
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
    }
    if ($kind === 'incorrect') {
        $token = 'invalid-token';
    }
    if ($kind === 'foreign') {
        $token = Password::createToken(pendingEmployee());
    }
    $this->post(route('password.update'), acceptInvitation($employee, $token))->assertSessionHasErrors('email');
    expect($employee->fresh()->password)->toBe($password)->and($employee->fresh()->hasPendingStaffInvitation())->toBeTrue();
})->with(['expired', 'incorrect', 'foreign']);

test('a suspended pending employee cannot redeem a valid token', function () {
    $employee = pendingEmployee();
    $token = Password::createToken($employee);
    $employee->forceFill(['suspended_at' => now()])->save();
    $this->post(route('password.update'), acceptInvitation($employee, $token))->assertSessionHasErrors('email');
    expect($employee->fresh()->hasPendingStaffInvitation())->toBeTrue()->and($employee->fresh()->email_verified_at)->toBeNull();
});

test('obsolete delivery jobs do not send invitations', function (string $state) {
    Notification::fake();
    $employee = pendingEmployee();
    $job = new SendEmployeeInvitation($employee->id, sha1($employee->email));
    if ($state === 'email') {
        $employee->update(['email' => 'changed@example.test']);
    }
    if ($state === 'role') {
        $employee->forceFill(['role' => 'customer'])->save();
    }
    if ($state === 'suspended') {
        $employee->forceFill(['suspended_at' => now()])->save();
    }
    if ($state === 'accepted') {
        $employee->forceFill(['staff_invitation_accepted_at' => now()])->save();
    }
    if ($state === 'deleted') {
        $employee->delete();
    }
    $job->handle();
    Notification::assertNothingSent();
})->with(['email', 'role', 'suspended', 'accepted', 'deleted']);

test('admin may resend pending invitations but cannot send them to suspended or accepted accounts', function (string $state) {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $employee = pendingEmployee();
    if ($state === 'suspended') {
        $employee->forceFill(['suspended_at' => now()])->save();
    }
    if ($state === 'accepted') {
        $employee->forceFill(['staff_invitation_accepted_at' => now()])->save();
    }
    $response = $this->actingAs($admin)->post(route('admin.employees.invitation', $employee));
    if ($state === 'pending') {
        $response->assertSessionHasNoErrors();
        Queue::assertPushed(SendEmployeeInvitation::class);
        expect(AuditLog::sole()->getAttribute('action'))->toBe('employee.invitation_requested');
    } else {
        $response->assertSessionHasErrors('invitation');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('audit_logs', 0);
    }
})->with(['pending', 'suspended', 'accepted']);

test('delivery failure leaves the account intact and pending for retry', function () {
    $employee = pendingEmployee();
    $before = $employee->fresh()->getAttributes();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic mail failure'));
    expect(fn () => (new SendEmployeeInvitation($employee->id, sha1($employee->email)))->handle())->toThrow(RuntimeException::class, 'Synthetic mail failure');
    expect($employee->fresh()->getAttributes())->toBe($before);
});

test('password setup cannot inject an administrator role', function () {
    $employee = pendingEmployee();
    $token = Password::createToken($employee);
    $this->post(route('password.update'), [...acceptInvitation($employee, $token), 'role' => 'admin'])->assertSessionHasErrors('role');
    expect($employee->fresh()->role->value)->toBe('employee')->and($employee->fresh()->hasPendingStaffInvitation())->toBeTrue();
});
