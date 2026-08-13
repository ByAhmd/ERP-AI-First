<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use App\Observers\AccountObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An account in the chart of accounts.
 *
 * Parent accounts group and total; only leaf accounts receive postings. The
 * distinction is held in `is_postable` and maintained by
 * {@see AccountObserver}, because it is read on every journal
 * line and cannot afford a recursive lookup each time.
 *
 * @property AccountType $type
 * @property bool $is_postable
 * @property bool $is_active
 * @property bool $is_system
 * @property int $depth
 * @property ?string $path
 * @property ?string $company_id
 * @property ?string $system_key
 */
#[Fillable([
    'company_id', 'parent_id', 'code', 'name', 'name_en', 'type',
    'description', 'is_postable', 'is_active', 'is_system', 'system_key',
    'currency_id',
])]
class Account extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'chart_of_accounts';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_postable' => true,
        'is_active' => true,
        'is_system' => false,
        'depth' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'depth' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function normalBalance(): NormalBalance
    {
        return $this->type->normalBalance();
    }

    /**
     * Whether a journal line may reference this account.
     *
     * All three conditions matter: posting to a group account makes its total
     * disagree with its children, and posting to a deactivated account
     * reanimates something the company deliberately retired.
     */
    public function acceptsPostings(): bool
    {
        return $this->is_postable && $this->is_active && ! $this->trashed();
    }

    /**
     * Whether this account has ledger history.
     *
     * Used to refuse structural changes — reclassifying or deleting an account
     * with postings would silently restate prior periods.
     */
    public function hasLedgerHistory(): bool
    {
        return $this->journalLines()->exists();
    }

    public function displayName(): string
    {
        $name = app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;

        return "{$this->code} — {$name}";
    }

    /**
     * Descendants of this account, itself included.
     *
     * Uses the materialised path so a subtree total is a prefix scan rather than
     * a recursive walk.
     *
     * @return Builder<self>
     */
    public function scopeSubtreeOf(Builder $query, self $account): Builder
    {
        return $query->where(function (Builder $query) use ($account): void {
            $query->whereKey($account->getKey())
                ->orWhere('path', 'like', $account->path.'.%');
        });
    }
}
