<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Accounting\DocumentNumberAllocator;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Counter backing a gapless document series.
 *
 * Never written directly. {@see DocumentNumberAllocator}
 * is the only thing that touches `next_number`, because correctness depends on
 * the row being locked inside the caller's transaction.
 */
class DocumentSequence extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $fillable = [
        'company_id',
        'key',
        'scope',
        'prefix',
        'suffix',
        'next_number',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }

    /**
     * Render a number in this series' format.
     */
    public function format(int $number): string
    {
        return $this->prefix
            .str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT)
            .$this->suffix;
    }
}
