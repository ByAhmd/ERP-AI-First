<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Contact;
use App\Models\Currency;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers.
 *
 * Laid out as Qoyod lays it out — details, then billing address, then shipping,
 * then bank — because that is the order the people using this already fill a
 * customer in, and reordering it would cost them time for no benefit.
 *
 * Customers and suppliers are one model. This resource shows only the customer
 * side; suppliers arrive with the purchases slice, filtered the same way.
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
        return $schema->components([
            Section::make(__('sales.contacts.sections.details'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('sales.contacts.fields.code'))
                        ->helperText(__('sales.contacts.hints.code'))
                        ->maxLength(40),

                    TextInput::make('contact_name')
                        ->label(__('sales.contacts.fields.contact_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('organization_name')
                        ->label(__('sales.contacts.fields.organization_name'))
                        ->maxLength(255),

                    TextInput::make('tax_number')
                        ->label(__('sales.contacts.fields.tax_number'))
                        ->helperText(__('sales.contacts.hints.tax_number'))
                        ->maxLength(50),

                    TextInput::make('primary_contact_number')
                        ->label(__('sales.contacts.fields.primary_contact_number'))
                        ->tel()
                        ->maxLength(40),

                    TextInput::make('secondary_contact_number')
                        ->label(__('sales.contacts.fields.secondary_contact_number'))
                        ->tel()
                        ->maxLength(40),

                    TextInput::make('primary_email')
                        ->label(__('sales.contacts.fields.primary_email'))
                        ->email()
                        ->maxLength(255),

                    TextInput::make('secondary_email')
                        ->label(__('sales.contacts.fields.secondary_email'))
                        ->email()
                        ->maxLength(255),

                    TextInput::make('website')
                        ->label(__('sales.contacts.fields.website'))
                        ->url()
                        ->maxLength(255),

                    Select::make('currency_id')
                        ->label(__('sales.contacts.fields.currency'))
                        ->options(fn (): array => Currency::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->pluck('code', 'id')
                            ->all())
                        ->searchable(),

                    Select::make('status')
                        ->label(__('sales.contacts.fields.status'))
                        ->options(ContactStatus::class)
                        ->default(ContactStatus::Active)
                        ->selectablePlaceholder(false)
                        ->required(),

                    Toggle::make('is_pos')
                        ->label(__('sales.contacts.fields.is_pos')),

                    Toggle::make('is_government_entity')
                        ->label(__('sales.contacts.fields.is_government_entity'))
                        ->helperText(__('sales.contacts.hints.is_government_entity'))
                        // The observer refuses to clear it; disabling the
                        // control says so before the refusal rather than after.
                        ->disabled(fn (?Contact $record): bool => $record !== null && $record->is_government_entity)
                        ->dehydrated(),
                ])
                ->columns(2),

            Section::make(__('sales.contacts.sections.billing_address'))
                ->schema([
                    TextInput::make('billing_address')
                        ->label(__('sales.contacts.fields.address'))
                        ->maxLength(255),

                    TextInput::make('billing_building_number')
                        ->label(__('sales.contacts.fields.building_number'))
                        ->helperText(__('sales.contacts.hints.building_number'))
                        ->maxLength(20),

                    TextInput::make('billing_city')
                        ->label(__('sales.contacts.fields.city'))
                        ->maxLength(100),

                    TextInput::make('billing_state')
                        ->label(__('sales.contacts.fields.state'))
                        ->maxLength(100),

                    TextInput::make('billing_zip')
                        ->label(__('sales.contacts.fields.zip'))
                        ->maxLength(20),

                    TextInput::make('billing_country')
                        ->label(__('sales.contacts.fields.country'))
                        ->maxLength(2)
                        ->default('SA'),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make(__('sales.contacts.sections.shipping_address'))
                ->schema([
                    TextInput::make('shipping_address')
                        ->label(__('sales.contacts.fields.address'))
                        ->maxLength(255),

                    TextInput::make('shipping_city')
                        ->label(__('sales.contacts.fields.city'))
                        ->maxLength(100),

                    TextInput::make('shipping_state')
                        ->label(__('sales.contacts.fields.state'))
                        ->maxLength(100),

                    TextInput::make('shipping_zip')
                        ->label(__('sales.contacts.fields.zip'))
                        ->maxLength(20),

                    TextInput::make('shipping_country')
                        ->label(__('sales.contacts.fields.country'))
                        ->maxLength(2),
                ])
                ->columns(2)
                ->collapsed(),

            Section::make(__('sales.contacts.sections.bank'))
                ->schema([
                    TextInput::make('bank_name')
                        ->label(__('sales.contacts.fields.bank_name'))
                        ->maxLength(255),

                    TextInput::make('bank_account_name')
                        ->label(__('sales.contacts.fields.bank_account_name'))
                        ->maxLength(255),

                    TextInput::make('bank_iban')
                        ->label(__('sales.contacts.fields.bank_iban'))
                        ->maxLength(40),

                    TextInput::make('bank_account_number')
                        ->label(__('sales.contacts.fields.bank_account_number'))
                        ->maxLength(40),

                    TextInput::make('bank_swift_code')
                        ->label(__('sales.contacts.fields.bank_swift_code'))
                        ->maxLength(20),

                    TextInput::make('bank_currency')
                        ->label(__('sales.contacts.fields.bank_currency'))
                        ->maxLength(3),

                    TextInput::make('bank_country')
                        ->label(__('sales.contacts.fields.bank_country'))
                        ->maxLength(2),

                    TextInput::make('bank_address')
                        ->label(__('sales.contacts.fields.bank_address'))
                        ->maxLength(255),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('sales.contacts.columns.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact_name')
                    ->label(__('sales.contacts.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('organization_name')
                    ->label(__('sales.contacts.columns.organization'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('primary_contact_number')
                    ->label(__('sales.contacts.columns.phone'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('primary_email')
                    ->label(__('sales.contacts.columns.email'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('tax_number')
                    ->label(__('sales.contacts.columns.tax_number'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('sales.contacts.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('sales.contacts.columns.status'))
                    ->options(ContactStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
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
