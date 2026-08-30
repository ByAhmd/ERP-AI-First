<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInvoices;

use App\Enums\PurchaseInvoiceKind;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\PurchaseInvoices\Pages\ViewPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Schemas\PurchaseInvoiceForm;
use App\Filament\Resources\PurchaseInvoices\Tables\PurchaseInvoicesTable;
use App\Models\PurchaseInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purchase invoices — فواتير المشتريات.
 *
 * The standard kind only: simple bills share the model and get their own
 * screen, the same one-model-two-resources split the contacts use. Drafts
 * are edited; an approved bill has reached the ledger and is corrected by
 * debit note.
 */
class PurchaseInvoiceResource extends Resource
{
    protected static ?string $model = PurchaseInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('purchases.invoices.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.invoices.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases.invoices.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('purchases.navigation_group');
    }

    /**
     * Standard bills only, on every query this resource makes — the same
     * base-query wall the contact resources build between their types.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', PurchaseInvoiceKind::Standard);
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseInvoiceForm::configure($schema);
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
            'index' => ListPurchaseInvoices::route('/'),
            'create' => CreatePurchaseInvoice::route('/create'),
            'edit' => EditPurchaseInvoice::route('/{record}/edit'),
            'view' => ViewPurchaseInvoice::route('/{record}'),
        ];
    }
}
