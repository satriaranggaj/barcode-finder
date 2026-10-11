<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Developer holds every super-admin privilege plus user management.
     * Use this for catalog/admin gates that super admins could already reach.
     */
    public function hasSuperAdminAccess(): bool
    {
        return $this->isSuperAdmin() || $this->isDeveloper();
    }

    /**
     * Who may open the user-management section at all.
     */
    public function canManageUsers(): bool
    {
        return $this->isSuperAdmin() || $this->isDeveloper();
    }

    /**
     * Roles the actor may assign. Super admins can never mint developers —
     * not even via crafted HTTP payloads, since validation reuses this list.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        if ($this->isDeveloper()) {
            return ['user', 'super_admin', 'developer'];
        }

        return ['user', 'super_admin'];
    }

    /**
     * Whether any developer account besides the given id exists.
     */
    public static function anotherDeveloperExists(?int $exceptId = null): bool
    {
        return static::query()->where('role', 'developer')
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
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
        ];
    }
}