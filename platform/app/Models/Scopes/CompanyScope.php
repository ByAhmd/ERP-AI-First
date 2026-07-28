<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the current company.
 *
 * The scope fails *closed*: when no company context is set it matches no rows,
 * rather than falling through to an unfiltered query. An unscoped read is only
 * reachable through {@see CompanyContext::withoutScoping()}, which is explicit
 * and greppable.
 *
 * This is the control that the predecessor system lacked. There, tenant filtering
 * depended on each service remembering to add a `where`, and on a permissions
 * guard that only ran for routes declaring required permissions — so any route
 * without one was readable across tenants.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $companyId = $context->id();

        if ($companyId === null) {
            // Fail closed. A missing context must never widen access.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
