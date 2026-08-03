<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Services\Accounting\DimensionAssigner;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class JournalEntryForm
{
    private const SCALE = 4;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('accounting.entries.sections.header'))
                ->schema([
                    DatePicker::make('entry_date')
                        ->label(__('accounting.entries.columns.date'))
                        ->required()
                        ->default(now())
                        ->native(false),

                    TextInput::make('reference')
                        ->label(__('accounting.entries.columns.reference'))
                        ->maxLength(100)
                        ->helperText(__('accounting.entries.hints.reference')),

                    TextInput::make('description')
                        ->label(__('accounting.entries.columns.description'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('accounting.entries.sections.lines'))
                ->schema([
                    Repeater::make('lines')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            Select::make('account_id')
                                ->label(__('accounting.entries.columns.account'))
                                ->options(fn (): array => self::postableAccounts())
                                ->searchable()
                                ->required()
                                ->columnSpan(2),

                            TextInput::make('debit')
                                ->label(__('accounting.normal_balance.debit'))
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                // A line is one side or the other. Clearing the
                                // opposite field resolves the ambiguity while
                                // typing rather than rejecting it on save.
                                ->afterStateUpdated(function (callable $set, ?string $state): void {
                                    if (filled($state) && (float) $state > 0) {
                                        $set('credit', 0);
                                    }
                                }),

                            TextInput::make('credit')
                                ->label(__('accounting.normal_balance.credit'))
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (callable $set, ?string $state): void {
                                    if (filled($state) && (float) $state > 0) {
                                        $set('debit', 0);
                                    }
                                }),

                            TextInput::make('description')
                                ->label(__('accounting.entries.columns.line_description'))
                                ->maxLength(255)
                                ->columnSpan(2),

                            Select::make('branch_id')
                                ->label(__('accounting.entries.columns.branch'))
                                ->options(fn (): array => Branch::query()
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable(),

                            // Dimensions are defined by the company, so the
                            // fields cannot be declared here — they are built
                            // from whatever the company has set up.
                            ...self::dimensionFields(),
                        ])
                        ->columns(5)
                        // Double entry needs two sides; starting with two rows
                        // spares the first two clicks every time.
                        ->defaultItems(2)
                        ->minItems(2)
                        ->addActionLabel(__('accounting.entries.actions.add_line'))
                        ->reorderable(false)
                        ->live()
                        // The running total is the point of this screen: an
                        // imbalance is visible while typing rather than being
                        // discovered at save.
                        ->helperText(fn (Get $get): string => self::balanceSummary($get('lines') ?? [])),
                ]),
        ]);
    }

    /**
     * One select per active dimension.
     *
     * Built at render time from the company's own dimensions, because the set is
     * user-defined and unknowable when this class is written. General dimensions
     * appear on every line; specific ones are offered too, since a manual
     * journal is where an accountant would reach for them.
     *
     * State is keyed by dimension id under `dimensions`, which is the shape
     * {@see DimensionAssigner} expects.
     *
     * @return list<Select>
     */
    private static function dimensionFields(): array
    {
        return Dimension::query()
            ->where('is_active', true)
            ->with(['values' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (Dimension $dimension): Select => Select::make("dimensions.{$dimension->getKey()}")
                ->label($dimension->displayName())
                ->options($dimension->values
                    ->mapWithKeys(fn (DimensionValue $value): array => [
                        $value->getKey() => $value->displayName(),
                    ])
                    ->all())
                ->searchable()
                ->required($dimension->is_required)
                // A dimension with no values yet would render an empty select
                // that cannot be satisfied.
                ->hidden($dimension->values->isEmpty()))
            ->all();
    }

    /**
     * Only leaf accounts appear. Offering group accounts would let a user build
     * an entry the poster is bound to refuse.
     *
     * @return array<string, string>
     */
    private static function postableAccounts(): array
    {
        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [
                $account->getKey() => $account->displayName(),
            ])
            ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    private static function balanceSummary(array $lines): string
    {
        $debit = '0';
        $credit = '0';

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $debit = bcadd($debit, self::amount($line['debit'] ?? null), self::SCALE);
            $credit = bcadd($credit, self::amount($line['credit'] ?? null), self::SCALE);
        }

        $difference = bcsub($debit, $credit, self::SCALE);

        if (bccomp($difference, '0', self::SCALE) === 0 && bccomp($debit, '0', self::SCALE) > 0) {
            return __('accounting.entries.balance.balanced', [
                'total' => number_format((float) $debit, 2),
            ]);
        }

        return __('accounting.entries.balance.unbalanced', [
            'debit' => number_format((float) $debit, 2),
            'credit' => number_format((float) $credit, 2),
            'difference' => number_format(abs((float) $difference), 2),
        ]);
    }

    private static function amount(mixed $value): string
    {
        if (blank($value) || ! is_numeric($value)) {
            return '0';
        }

        return (string) $value;
    }
}
