<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The VAT rates a Saudi company starts with.
 *
 * The same three Qoyod provisions, and for the same reason: every supply a
 * Saudi business makes is standard-rated, zero-rated or exempt, and a return
 * cannot be filed without telling the last two apart. They are seeded as system
 * rates — renameable, not deletable — because documents resolve them by
 * category and a company that deleted "exempt" could no longer invoice an
 * exempt supply.
 *
 * Idempotent, like {@see ChartOfAccountsTemplate}:
 * re-running adds whatever is missing and leaves existing rates, including
 * renamed and re-rated ones, untouched. A company that moved to a new VAT rate
 * must not have it silently moved back.
 */
final class TaxTemplate
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountRegistry $registry,
    ) {}

    /**
     * Apply the template to a company.
     *
     * @return int Number of rates created.
     */
    public function applyTo(Company $company): int
    {
        return $this->context->forCompany($company, function (): int {
            $this->registry->flush();

            $account = $this->registry->get(SystemAccount::VatOutputPayable);

            return DB::transaction(function () use ($account): int {
                $created = 0;

                foreach ($this->definition() as $rate) {
                    if (Tax::query()->where('category', $rate['category'])->exists()) {
                        continue;
                    }

                    Tax::create([
                        'name' => $rate['name'],
                        'name_en' => $rate['name_en'],
                        'category' => $rate['category'],
                        'rate' => $rate['rate'],
                        'is_default' => $rate['is_default'],
                        'account_id' => $account->getKey(),
                        'is_system' => true,
                    ]);

                    $created++;
                }

                return $created;
            });
        });
    }

    /**
     * @return list<array{name: string, name_en: string, category: TaxCategory, rate: string, is_default: bool}>
     */
    private function definition(): array
    {
        return [
            [
                'name' => 'ضريبة القيمة المضافة',
                'name_en' => 'Value Added Tax',
                'category' => TaxCategory::Standard,
                'rate' => '15',
                // The rate a line falls back to when none is chosen, because
                // standard-rated is what most supplies are.
                'is_default' => true,
            ],
            [
                'name' => 'الضريبة الصفرية',
                'name_en' => 'Zero Rated',
                'category' => TaxCategory::ZeroRated,
                'rate' => '0',
                'is_default' => false,
            ],
            [
                'name' => 'معفاة من الضريبة',
                'name_en' => 'Exempt',
                'category' => TaxCategory::Exempt,
                'rate' => '0',
                'is_default' => false,
            ],
        ];
    }
}
