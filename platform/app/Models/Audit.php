<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * An audit record.
 *
 * Extends the package model to add the company relationship, since audit rows
 * carry a denormalised `company_id` (see docs/database — exception 1) so that a
 * company's history can be read, retained and purged without joining every
 * audited table.
 *
 * Deliberately not using {@see \App\Models\Concerns\BelongsToCompany}: audit
 * rows are written by an observer that may run in any context, and the scope's
 * create-time assignment would conflict with the value the auditing package
 * supplies. Scoping for reads is applied by the Filament resource instead.
 */
class Audit extends BaseAudit
{
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A readable name for the audited record's type.
     */
    public function auditableLabel(): string
    {
        return class_basename((string) $this->auditable_type);
    }
}
