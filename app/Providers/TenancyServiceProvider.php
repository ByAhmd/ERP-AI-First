<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CompanyContext::class);
    }

    public function boot(): void
    {
        $this->isolateQueuedJobs();
    }

    /**
     * Clear the company context around every queued job.
     *
     * Queue workers are long-lived processes that handle jobs for many companies
     * in sequence. Without this, a job that sets a context would leave it in
     * place for whatever ran next — a cross-company data leak that would be
     * intermittent and extremely hard to reproduce.
     *
     * Jobs are expected to establish their own context from their payload.
     */
    private function isolateQueuedJobs(): void
    {
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(CompanyContext::class)->forget();
        });

        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(CompanyContext::class)->forget();
        });
    }
}
