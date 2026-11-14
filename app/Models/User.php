<?php

namespace App\Models;

use App\Mail\ProductionMailFailure;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string|null $display_name
 * @property string|null $bio
 * @property bool $is_private
 * @property string $theme_key
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'username', 'display_name', 'bio', 'is_private', 'theme_key', 'timezone'])]
#[Hidden(['password', 'remember_token', 'recovery_uuid'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $attributes = ['timezone' => 'UTC'];

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('recovery_uuid')) {
                throw new \LogicException('Account recovery identity is immutable.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['recovery_uuid'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        try {
            app('mail.manager')->assertSafeMailer();
            parent::sendPasswordResetNotification($token);
        } catch (\Throwable $exception) {
            if (app()->isProduction()) {
                throw new ProductionMailFailure;
            }
            throw $exception;
        }
    }

    public function sendEmailVerificationNotification()
    {
        try {
            app('mail.manager')->assertSafeMailer();
            parent::sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            if (app()->isProduction()) {
                throw new ProductionMailFailure;
            }
            throw $exception;
        }
    }

    public function markEmailAsVerified(): bool
    {
        return $this->getConnection()->transaction(function () {
            // Share the credential/deletion owner lock and reject a request
            // admitted before the identity or credentials changed.
            $fresh = static::query()->lockForUpdate()->find($this->id);
            abort_unless($fresh && $fresh->recovery_uuid === $this->recovery_uuid && $fresh->email === $this->email
                && hash_equals($fresh->password, $this->password), 403);
            if ($fresh->hasVerifiedEmail()) {
                $this->setRawAttributes($fresh->getAttributes(), true);

                return false;
            }
            $fresh->forceFill(['email_verified_at' => $fresh->freshTimestamp()])->save();
            $this->setRawAttributes($fresh->getAttributes(), true);

            return true;
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
            'is_private' => 'boolean',
        ];
    }

    public function localToday(): string
    {
        return now($this->timezone ?? 'UTC')->toDateString();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function following(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    public function followers(): HasMany
    {
        return $this->hasMany(Follow::class, 'followed_id');
    }

    public function sharedName(): string
    {
        return $this->display_name ?? $this->username ?? 'Unavailable profile';
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * Get the user's preferred display name, falling back to registration name.
     */
    public function displayName(): string
    {
        return $this->display_name ?? $this->name;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->displayName(), true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
