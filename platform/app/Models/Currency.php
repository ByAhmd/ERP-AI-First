<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A currency the company transacts in.
 *
 * Per company rather than global: a company enables the few it uses, and the
 * base currency named on the company record is one of them.
 */
#[Fillable([
    'company_id', 'code', 'name', 'symbol', 'decimal_places', 'is_active',
])]
class Currency extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'decimal_places' => 2,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ExchangeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    /**
     * The rate to use for a given date.
     *
     * Falls back to the most recent earlier rate rather than failing: a rate is
     * published on trading days, and a document dated on a weekend must still
     * translate. Returns null when no rate has ever been published.
     */
    public function rateOn(\DateTimeInterface $date): ?ExchangeRate
    {
        return $this->rates()
            ->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();
    }

    public function isBaseFor(Company $company): bool
    {
        return $this->code === $company->base_currency;
    }
}
