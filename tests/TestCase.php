<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register migrations that belong to the suite rather than the product.
     *
     * This has to happen here, and it has to happen at this point. Laravel's
     * setUp resolves the application, then runs trait hooks, then runs
     * afterApplicationCreated callbacks — so RefreshDatabase has already
     * migrated by the time the usual hook fires. Registering the path while
     * the application is being refreshed is the last moment that is early
     * enough.
     *
     * It is also process-wide by necessity: RefreshDatabase migrates once for
     * the whole run, guarded by a static flag, so a path registered by one
     * test class would be missed entirely unless that class happened to run
     * first.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->app->make('migrator')->path(__DIR__.'/database/migrations');
    }
}
