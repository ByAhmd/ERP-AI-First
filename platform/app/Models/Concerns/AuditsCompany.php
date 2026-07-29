<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\CompanyContext;
use OwenIt\Auditing\Auditable;

/**
 * Auditing, with the owning company stamped onto each record.
 *
 * Composes the auditing package's own trait rather than sitting alongside it:
 * both define `transformAudit`, and resolving that collision in every model
 * would repeat the same three lines indefinitely. Models apply this trait alone
 * and get both behaviours.
 *
 * The company is denormalised onto the audit row (see docs/database, exception
 * 1) so that a company's history can be read, retained and purged without
 * joining every audited table.
 */
trait AuditsCompany
{
    use Auditable {
        Auditable::transformAudit as protected packageTransformAudit;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $data = $this->packageTransformAudit($data);

        // Prefer the record's own company over the ambient context, so an audit
        // row is attributed to the company that owns the data even when a
        // platform-level process wrote it.
        $data['company_id'] = $this->getAttribute('company_id')
            ?? app(CompanyContext::class)->id();

        return $data;
    }
}
