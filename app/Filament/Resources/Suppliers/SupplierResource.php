<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Enums\ContactType;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Suppliers — الموردين.
 *
 * The mirror of CustomerResource on the same contact model and the same
 * shared form, exactly as Qoyod mirrors them. Only the type filter, the
 * wording and the reference series differ — the observer numbers suppliers
 * from their own per-type counter.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'contact_name';

    public static function getModelLabel(): string
    {
        return __('purchases.suppliers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.suppliers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases.suppliers.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('purchases.navigation_group');
    }

    /**
     * Only suppliers, on every query this resource makes.
     *
     * The same base-query filter the customer side applies, for the same
     * reason: without it, pasting a customer's id into the edit route would
     * reach a record this screen has no business showing.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', ContactType::Supplier);
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema, __('purchases.suppliers.fields.contact_name'));
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table, __('purchases.suppliers.columns.name'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
