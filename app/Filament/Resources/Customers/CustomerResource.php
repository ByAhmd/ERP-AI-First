<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Enums\ContactType;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers.
 *
 * Customers and suppliers are one model and one shared form, as they are in
 * Qoyod — both of its menu items point at the same contact record. This
 * resource shows only the customer side; SupplierResource is the mirror.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'contact_name';

    public static function getModelLabel(): string
    {
        return __('sales.contacts.customer_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.contacts.customers_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.contacts.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    /**
     * Only customers, on every query this resource makes.
     *
     * Applied to the base query rather than the table, so the edit and delete
     * routes cannot be used to reach a supplier by pasting its id.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', ContactType::Customer);
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema, __('sales.contacts.fields.contact_name'));
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table, __('sales.contacts.columns.name'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
