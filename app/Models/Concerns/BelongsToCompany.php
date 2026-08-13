<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\Exceptions\CompanyContextMissing;
use App\Support\Tenancy\Exceptions\CompanyMismatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as owned by exactly one company.
 *
 * Applying this trait gives the model three guarantees:
 *
 *  1. Reads are filtered to the current company, failing closed when none is set.
 *  2. `company_id` is populated on create from the current context.
 *  3. `company_id` can never change afterwards.
 *
 * Every tenant-owned table in the platform uses this. Enforcement lives here
 * rather than in individual services precisely so that forgetting it is not
 * possible — the previous system's isolation defect was a missing `where`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $context = app(CompanyContext::class);
            $companyId = $context->id();

            if ($companyId === null) {
                // Seeders and platform-level tooling run inside withoutScoping()
                // and are expected to set company_id explicitly.
                if ($context->isBypassed()) {
                    return;
                }

                throw CompanyContextMissing::forModel($model);
            }

            $model->setAttribute('company_id', $companyId);
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw CompanyMismatch::onUpdate($model);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
