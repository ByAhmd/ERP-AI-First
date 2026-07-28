<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\CompanyContext;

/**
 * Stamps the owning company onto each audit record.
 *
 * Without this, answering "show me everything that changed in this company last
 * quarter" would require a union across every audited table. Retention and
 * tenant data export need the same thing.
 *
 * Prefers the record's own company_id over the ambient context, so an audit row
 * is attributed to the company that owns the data even if a platform-level
 * process wrote it.
 */
trait AuditsCompany
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $data['company_id'] = $this->getAttribute('company_id')
            ?? app(CompanyContext::class)->id();

        return $data;
    }
}
