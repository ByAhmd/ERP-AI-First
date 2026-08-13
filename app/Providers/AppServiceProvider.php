<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Dimension;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Tax;
use App\Observers\AccountObserver;
use App\Observers\ContactObserver;
use App\Observers\DimensionObserver;
use App\Observers\JournalEntryObserver;
use App\Observers\ProductObserver;
use App\Observers\TaxObserver;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureSchemaMacros();
        $this->configureDates();
        $this->configureAuthorization();
        $this->registerObservers();
    }

    /**
     * Ledger and chart-of-accounts invariants.
     *
     * Registered as observers rather than enforced in services so they hold on
     * every path that reaches the model — imports, jobs, console commands and
     * code not yet written.
     */
    private function registerObservers(): void
    {
        Account::observe(AccountObserver::class);
        Contact::observe(ContactObserver::class);
        Dimension::observe(DimensionObserver::class);
        JournalEntry::observe(JournalEntryObserver::class);
        Product::observe(ProductObserver::class);
        Tax::observe(TaxObserver::class);
    }

    /**
     * Bind Shield's generated policies to their models.
     *
     * Shield writes policies for models that live outside Laravel's
     * `App\Models` / `App\Policies` discovery convention — the Role resource
     * being the first. Without this registration those policies exist on disk
     * but are never consulted, so the resource would silently authorise
     * everyone.
     */
    private function configureAuthorization(): void
    {
        FilamentShield::enforcePolicies();
    }

    /**
     * Fail loudly on developer mistakes rather than degrading silently.
     *
     * Strict mode surfaces lazy-loading (the usual cause of N+1 on ledger
     * listings), silently discarded attributes, and accessing attributes that
     * were never selected — all of which otherwise reach production as
     * "mysteriously slow" or "mysteriously blank".
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }

    /**
     * Schema macros for the platform's recurring column shapes.
     *
     * Defining monetary precision in one place means a future change to scale is
     * a single edit rather than an audit of every migration.
     */
    private function configureSchemaMacros(): void
    {
        Blueprint::macro('money', function (string $column, bool $nullable = false) {
            /** @var Blueprint $this */
            $definition = $this->decimal($column, 19, config('erp.money_scale'));

            return $nullable ? $definition->nullable() : $definition->default(0);
        });

        Blueprint::macro('exchangeRate', function (string $column = 'exchange_rate') {
            /** @var Blueprint $this */
            // Rates are quoted to six places by every central bank we consume.
            return $this->decimal($column, 19, 6)->nullable();
        });

        Blueprint::macro('quantity', function (string $column = 'quantity') {
            /** @var Blueprint $this */
            // Four places supports fractional units of measure (kg, litres, hours).
            return $this->decimal($column, 19, 4)->default(0);
        });
    }

    /**
     * Timestamps are stored and reasoned about in UTC; presentation converts to
     * the company's timezone. Mixing the two in storage is a common source of
     * off-by-one errors on period boundaries, which in accounting means a
     * transaction landing in the wrong month.
     */
    private function configureDates(): void
    {
        Date::use(Carbon::class);
    }
}
