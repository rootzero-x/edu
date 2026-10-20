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
        'phone', 'password', 'first_name', 'last_name', 'role', 'branch_id', 'active', 'locale',
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

    public function can($abilities, $arguments = []): bool
    {
        if (is_string($abilities) && str_contains($abilities, '.')) {
            return $this->active && $this->role->can($abilities);
        }

        return parent::can($abilities, $arguments);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'role' => $this->role->value,
            'branch_id' => $this->branch_id,
            'locale' => $this->locale,
        ];
    }
}
