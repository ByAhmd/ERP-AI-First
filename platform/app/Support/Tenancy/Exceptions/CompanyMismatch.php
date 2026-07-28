<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Raised when a record's owning company would change.
 *
 * Ownership is immutable. Moving a posted invoice or journal line between
 * companies would silently corrupt both companies' ledgers, so it is refused
 * outright rather than permitted under a policy check.
 */
final class CompanyMismatch extends RuntimeException
{
    public static function onUpdate(Model $model): self
    {
        return new self(sprintf(
            'The owning company of [%s:%s] cannot be changed after creation.',
            $model::class,
            (string) $model->getKey(),
        ));
    }
}
