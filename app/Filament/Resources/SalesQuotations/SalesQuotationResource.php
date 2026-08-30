<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesQuotations;

use App\Filament\Resources\SalesQuotations\Pages\CreateSalesQuotation;
use App\Filament\Resources\SalesQuotations\Pages\EditSalesQuotation;
use App\Filament\Resources\SalesQuotations\Pages\ListSalesQuotations;
use App\Filament\Resources\SalesQuotations\Pages\ViewSalesQuotation;
use App\Filament\Resources\SalesQuotations\Schemas\SalesQuotationForm;
use App\Filament\Resources\SalesQuotations\Tables\SalesQuotationsTable;
use App\Models\SalesQuotation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Sales quotations — عروض الأسعار.
 *
 * Qoyod's sales flow reads: العملاء، عروض الأسعار، فواتير المبيعات — the
 * quotation sits between the customer and the invoice, which is where the
 * navigation puts it. Drafts are edited; anything past draft is read, and the
 * only actions on an approved quotation are conversion and cancellation.
 */
class SalesQuotationResource extends Resource
{
    protected static ?string $model = SalesQuotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('sales.quotations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.quotations.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.quotations.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return SalesQuotationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesQuotationsTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSalesQuotations::route('/'),
            'create' => CreateSalesQuotation::route('/create'),
            'edit' => EditSalesQuotation::route('/{record}/edit'),
            'view' => ViewSalesQuotation::route('/{record}'),
        ];
    }
}
