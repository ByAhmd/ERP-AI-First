<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\RoleProvisioner;
use Illuminate\Console\Command;

/**
 * Reconciles every company's administrator role with the current permission set.
 *
 * Run this after `shield:generate`. Deployments that add Filament resources
 * should run it too, or existing companies' administrators silently lose access
 * to the new screens.
 */
final class SyncCompanyRoles extends Command
{
    protected $signature = 'erp:sync-roles';

    protected $description = "Sync each company's administrator role with all generated permissions";

    public function handle(RoleProvisioner $provisioner): int
    {
        $count = $provisioner->provisionAllCompanies();

        $this->info("Administrator role synced for {$count} " . str('company')->plural($count) . '.');

        return self::SUCCESS;
    }
}
