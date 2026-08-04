<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dimension value tagged onto one ledger line.
 *
 * A separate table rather than columns on the line, because the set of
 * dimensions is defined by the company and cannot be known at migration time.
 * One row per dimension per line, enforced by a unique index — a second value
 * for the same dimension would make every total double-count that line.
 */
#[Fillable([
    'company_id', 'journal_entry_line_id', 'dimension_id',
    'dimension_value_id',
])]
class JournalEntryLineDimension extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $table = 'journal_entry_line_dimensions';

    /**
     * @return BelongsTo<JournalEntryLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id');
    }

    /**
     * @return BelongsTo<Dimension, $this>
     */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(Dimension::class);
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function value(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'dimension_value_id');
    }
}
