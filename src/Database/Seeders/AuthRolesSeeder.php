<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AuthRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Fail loud with a helpful hint instead of a cryptic SQL error when
        // Spatie's permission tables haven't been published + migrated yet.
        if (! Schema::hasTable('roles')) {
            throw new RuntimeException(
                "AuthRolesSeeder: the `roles` table does not exist. Spatie Permission's "
                . "migrations have not been published or run yet.\n\n"
                . "Fix it by running:\n"
                . "  php artisan auth:install            # one-shot installer (recommended)\n"
                . "\n"
                . "Or, manually:\n"
                . "  php artisan vendor:publish --provider=\"Spatie\\Permission\\PermissionServiceProvider\" --tag=permission-migrations\n"
                . "  php artisan migrate\n"
            );
        }

        $roles = config('auth_system.roles.seeded_roles', ['super-admin', 'admin', 'user']);

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name'       => $role,
                'guard_name' => 'web',
            ]);
        }
    }
}
