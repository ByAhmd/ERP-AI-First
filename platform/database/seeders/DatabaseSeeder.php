<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Permissions first: the administrator role provisioned by
            // FirstRunSeeder syncs whatever exists at that moment.
            PermissionSeeder::class,
            FirstRunSeeder::class,
        ]);
    }
}
