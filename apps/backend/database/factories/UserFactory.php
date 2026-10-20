<?php

namespace Database\Factories;

use App\Domain\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone' => '+9989'.fake()->unique()->numerify('########'),
            'password' => 'secret-password',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'role' => Role::Student,
            'active' => true,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
