<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaxCategory;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A VAT rate a document line may be charged at.
 *
 * Audited, because the rate a supply was taxed at is a fact ZATCA can ask about
 * years later, and "it says 15% now" is not an answer to what it said then.
 *
 * @property TaxCategory $category
 * @property string $rate
 * @property bool $is_active
 * @property bool $is_default
 * @property bool $is_system
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'name', 'name_en', 'category', 'rate',
    'account_id', 'is_active', 'is_default', 'is_system',
])]
class Tax extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rate' => 0,
        'is_active' => true,
        'is_default' => false,
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'category' => TaxCategory::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function displayName(): string
    {
        $name = app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;

        return $name.' ('.$this->formattedRate().')';
    }

    /**
     * The rate as a percentage, trimmed of trailing noise.
     *
     * Stored at four decimals so a rate like 2.5% survives, but 15.0000% reads
     * badly on a document line.
     */
    public function formattedRate(): string
    {
        return rtrim(rtrim(number_format((float) $this->rate, 4), '0'), '.').'%';
    }

    /**
     * The multiplier a line applies, as a bcmath-safe string.
     *
     * Returned as a fraction rather than a percentage so callers never divide
     * by 100 themselves — the place a rounding error would otherwise enter the
     * ledger.
     */
    public function fraction(): string
    {
        return bcdiv((string) $this->rate, '100', 6);
    }

    public function isZero(): bool
    {
        return bccomp((string) $this->rate, '0', 4) === 0;
    }
}
