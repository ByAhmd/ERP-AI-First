<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The contact form, shared by customers and suppliers.
 *
 * Qoyod serves both from one form — both menu items point at the same contact
 * record — and this schema is one for the same reason. The only wording that
 * differs between the two screens is what the name field is called, so that is
 * the one thing the caller supplies.
 */
class ContactForm
{
    public static function configure(Schema $schema, string $nameLabel): Schema
    {
        return $schema->components([
            Section::make(__('sales.contacts.sections.details'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('sales.contacts.fields.code'))
                        ->helperText(__('sales.contacts.hints.code'))
                        ->maxLength(40),

                    TextInput::make('contact_name')
                        ->label($nameLabel)
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
}
