<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published rate for one currency on one date.
 *
 * Expressed as units of the company's base currency per one unit of the quoted
 * currency, so translating to base is always a multiplication. Fixing the
 * direction here avoids the inverted-rate class of bug entirely.
 */
class ExchangeRate extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $fillable = [
        'company_id',
        'currency_id',
        'rate_date',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
