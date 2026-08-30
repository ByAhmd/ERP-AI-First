<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices;

use App\Enums\PurchaseInvoiceKind;
use App\Filament\Resources\PurchaseInvoices\Tables\PurchaseInvoicesTable;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\CreateSimplePurchaseInvoice;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\EditSimplePurchaseInvoice;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\ListSimplePurchaseInvoices;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\ViewSimplePurchaseInvoice;
use App\Filament\Resources\SimplePurchaseInvoices\Schemas\SimplePurchaseInvoiceForm;
use App\Models\PurchaseInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Simple purchase invoices — فواتير بسيطة.
 *
 * The same model as the standard bill behind a leaner screen: an expense
 * keyed straight to accounts, with no products, no quantities and no due
 * date. One table serves both kinds so a simple bill appears in every
 * payable query — outstanding, the voucher picker, the supplier statement —
 * without anyone remembering a UNION.
 */
class SimplePurchaseInvoiceResource extends Resource
{
    protected static ?string $model = PurchaseInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('purchases.simple_invoices.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.simple_invoices.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases.simple_invoices.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('purchases.navigation_group');
    }

    /**
     * Simple bills only, on every query — the same base-query wall the
     * standard resource builds from the other side.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', PurchaseInvoiceKind::Simple);
    }

    public static function form(Schema $schema): Schema
    {
        return SimplePurchaseInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseInvoicesTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSimplePurchaseInvoices::route('/'),
            'create' => CreateSimplePurchaseInvoice::route('/create'),
            'edit' => EditSimplePurchaseInvoice::route('/{record}/edit'),
            'view' => ViewSimplePurchaseInvoice::route('/{record}'),
        ];
    }
}
