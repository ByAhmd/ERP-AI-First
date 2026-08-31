<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\Company;

/**
 * The default branch every company must hold.
 *
 * Locations are branches, and stock-bearing documents need one to say where
 * goods physically moved — so provisioning guarantees at least one exists
 * and exactly one is the default the forms fall back to. Idempotent, like
 * the chart template: an existing default is left alone.
 */
final class BranchTemplate
{
    public function applyTo(Company $company): Branch
    {
        $default = Branch::query()
            ->where('company_id', $company->getKey())
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            return $default;
        }

        $oldest = Branch::query()
            ->where('company_id', $company->getKey())
            ->orderBy('created_at')
            ->first();

        if ($oldest !== null) {
            $oldest->forceFill(['is_default' => true])->save();

            return $oldest;
        }

        return Branch::create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'المركز الرئيسي',
            'name_en' => 'Main Branch',
            'is_default' => true,
        ]);
    }
}
