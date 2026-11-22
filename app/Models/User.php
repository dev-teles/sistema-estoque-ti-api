<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasRowLevelSecurity;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRowLevelSecurity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ultimo_login_em' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'ativo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('password') && ! empty($user->password) && Hash::needsRehash($user->password)) {
                $user->password = Hash::make($user->password);
            }
        });
        static::creating(fn () => static::rlsRequireRole('admin'));
        static::deleting(fn () => static::rlsRequireRole('admin'));
        static::updating(function (User $target) {
            $actor = static::rlsUser();
            if ($actor && ! $actor->isAdmin()) {
                if ((int) $target->id !== (int) $actor->id || $target->isDirty(['role', 'ativo'])) {
                    throw new AuthorizationException('RLS: apenas admin pode alterar outros utilizadores ou privilégios.');
                }
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof Role ? $role : Role::from($role);
            if ($this->role === $role) {
                return true;
            }
        }

        return false;
    }
}
