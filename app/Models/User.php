<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property UserRole $role
 * @property Carbon|null $suspended_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $staff_invited_at
 * @property Carbon|null $staff_invitation_accepted_at
 */
#[Fillable(['name', 'first_name', 'last_name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'bootstrap_key'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @var array<string, mixed> */
    protected $attributes = ['role' => 'customer'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty(['first_name', 'last_name']) && $user->first_name !== null && $user->last_name !== null) {
                $user->name = $user->first_name.' '.$user->last_name;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'role' => UserRole::class,
            'staff_invited_at' => 'datetime',
            'staff_invitation_accepted_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function hasPendingStaffInvitation(): bool
    {
        return $this->staff_invited_at !== null && $this->staff_invitation_accepted_at === null;
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /** @return Attribute<string|null, string|null> */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : PhoneNumber::normalize($value));
    }
}
