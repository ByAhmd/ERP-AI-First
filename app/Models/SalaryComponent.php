<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentKind;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A salary component — Qoyod's مكونات الرواتب.
 *
 * Allowances and recurring deductions as company-level types, each
 * carrying its own account: allowances an expense, deductions an income —
 * Qoyod's own mapping. The keyed system accounts are only form defaults.
 *
 * @property SalaryComponentKind $kind
 * @property SalaryComponentCalculation $calculation
 * @property bool $counts_toward_gosi
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'name', 'name_en', 'kind', 'calculation', 'account_id',
    'counts_toward_gosi',
])]
class SalaryComponent extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'counts_toward_gosi' => false,
    ];

    protected function casts(): array
    {
        return [
            'kind' => SalaryComponentKind::class,
            'calculation' => SalaryComponentCalculation::class,
            'counts_toward_gosi' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<EmployeeSalaryComponent, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? (string) $this->name_en
            : (string) $this->name;
    }
}
