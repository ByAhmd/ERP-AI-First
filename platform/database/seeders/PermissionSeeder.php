<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Identity\RoleProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * Derives the permission set from the panel's Filament resources.
 *
 * Permissions are data, not schema, so a migrated-but-unseeded database has
 * none — which makes every policy deny. Generating them here rather than
 * committing a static list means the set cannot drift from the resources it
 * describes: adding a resource and forgetting its permissions is not possible.
 *
 * Roles are provisioned separately, by {@see RoleProvisioner},
 * because roles are company-scoped and permissions are not.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);

        // The registrar caches permissions; without this, anything provisioned
        // in the same process sees the pre-seed (empty) set.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
