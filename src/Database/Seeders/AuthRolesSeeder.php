<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AuthRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = config('auth_system.roles.seeded_roles', ['super-admin', 'admin', 'user']);

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name'       => $role,
                'guard_name' => 'web',
            ]);
        }
    }
}
