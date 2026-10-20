<?php

namespace App\Support;

use App\Domain\Role;

final class Permissions
{
    public function __construct(private Settings $settings) {}

    public function has(Role $role, string $permission): bool
    {
        if ($role === Role::SuperAdmin) {
            return true;
        }
        $matrix = $this->settings->get('roles.permissions');

        return in_array($permission, $matrix[$role->value] ?? [], true);
    }

    /** @return list<string> */
    public function forRole(Role $role): array
    {
        return $role === Role::SuperAdmin
            ? config('tisu.permissions')
            : array_values($this->settings->get('roles.permissions')[$role->value] ?? []);
    }
}
