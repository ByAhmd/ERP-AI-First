<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears;

use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Resources\FiscalYears\Tables\FiscalYearsTable;
use App\Models\FiscalYear;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Fiscal years and their periods.
 *
 * Years are generated rather than typed: the start comes from the company's
 * configured fiscal start, and the twelve periods follow from it. Hand-entered
 * dates would let periods overlap or leave gaps, and a date falling in no
 * period cannot be posted to at all.
 */
class FiscalYearResource extends Resource
{
    protected static ?string $model = FiscalYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('accounting.fiscal_years.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.fiscal_years.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.navigation_group');
    }

    public static function table(Table $table): Table
    {
        return FiscalYearsTable::configure($table);
    }

    /**
     * Created through the generator action, never a blank form.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalYears::route('/'),
        ];
    }
}
