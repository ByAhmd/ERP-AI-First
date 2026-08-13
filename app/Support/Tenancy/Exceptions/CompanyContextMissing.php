<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Raised when tenant-owned data is written without a resolved company.
 *
 * This is a programming error, not a user error: it means a job, command or
 * service reached a write without establishing which company it acts for.
 * Failing here is preferable to persisting a row that no company owns.
 */
final class CompanyContextMissing extends RuntimeException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf(
            'Cannot create [%s] without a company context. Set one via CompanyContext::set() '
            .'or CompanyContext::forCompany(), or assign company_id explicitly.',
            $model::class,
        ));
    }

    public static function forOperation(): self
    {
        return new self(
            'This operation requires a company context, but none was set. '
            .'Queued jobs and console commands must establish one explicitly.',
        );
    }
}
