<?php

namespace App\Models;

use App\Domain\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'phone', 'password', 'first_name', 'last_name', 'role', 'active', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    public function hasPermission(string $permission): bool
    {
        return $this->active && app(\App\Support\Permissions::class)->has($this->role, $permission);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [Role::SuperAdmin, Role::Admin], true);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'role' => $this->role->value,
            'active' => $this->active,
            'locale' => $this->locale,
            'permissions' => app(\App\Support\Permissions::class)->forRole($this->role),
        ];
    }
}
