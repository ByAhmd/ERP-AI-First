<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\CurrentCompany;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Settings for the company currently selected in the panel.
 *
 * A page rather than a resource: within a tenant panel there is exactly one
 * company in scope, so a list-and-select interface would be misleading. Creating
 * and listing companies is platform administration and does not belong here.
 *
 * The address fields are ZATCA's structured postal address. They are required
 * for e-invoicing and cannot be reconstructed from a free-text block later.
 *
 * @property-read Schema $form
 */
class CompanySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.company-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('company.settings.nav_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('company.settings.title');
    }

    /**
     * Only members who may administer the company reach this page.
     */
    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            && Filament::auth()->user()?->can('update', $company) === true;
    }

    public function mount(): void
    {
        $this->form->fill($this->company()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('company.settings.sections.identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('company.fields.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('name_en')
                            ->label(__('company.fields.name_en'))
                            ->maxLength(255),

                        TextInput::make('commercial_registration_no')
                            ->label(__('company.fields.commercial_registration_no'))
                            ->maxLength(20)
                            ->unique(
                                table: 'companies',
                                ignorable: fn (): Company => $this->company(),
                            ),

                        TextInput::make('vat_registration_number')
                            ->label(__('company.fields.vat_registration_number'))
                            // Saudi VAT numbers are exactly 15 digits, beginning
                            // and ending with 3.
                            ->rule('regex:/^3\d{13}3$/')
                            ->helperText(__('company.fields.vat_hint'))
                            ->unique(
                                table: 'companies',
                                ignorable: fn (): Company => $this->company(),
                            ),
                    ])
                    ->columns(2),

                Section::make(__('company.settings.sections.address'))
                    ->description(__('company.settings.sections.address_hint'))
                    ->schema([
                        TextInput::make('building_number')
                            ->label(__('company.fields.building_number'))
                            ->rule('regex:/^\d{4}$/')
                            ->maxLength(4),

                        TextInput::make('street_name')
                            ->label(__('company.fields.street_name'))
                            ->maxLength(255),

                        TextInput::make('district')
                            ->label(__('company.fields.district'))
                            ->maxLength(255),

                        TextInput::make('city')
                            ->label(__('company.fields.city'))
                            ->maxLength(255),

                        TextInput::make('postal_code')
                            ->label(__('company.fields.postal_code'))
                            ->rule('regex:/^\d{5}$/')
                            ->maxLength(5),

                        TextInput::make('additional_number')
                            ->label(__('company.fields.additional_number'))
                            ->rule('regex:/^\d{4}$/')
                            ->maxLength(4),
                    ])
                    ->columns(3),

                Section::make(__('company.settings.sections.financial'))
                    ->schema([
                        TextInput::make('base_currency')
                            ->label(__('company.fields.base_currency'))
                            ->required()
                            ->maxLength(3)
                            // Changing this after transactions exist would
                            // reinterpret every posted amount.
                            ->disabled(fn (): bool => $this->company()->exists)
                            ->helperText(__('company.fields.base_currency_hint')),

                        Select::make('fiscal_year_start_month')
                            ->label(__('company.fields.fiscal_year_start_month'))
                            ->options(self::numericOptions(12))
                            ->required(),

                        Select::make('fiscal_year_start_day')
                            ->label(__('company.fields.fiscal_year_start_day'))
                            // Capped at 28 so the date is valid in February.
                            ->options(self::numericOptions(28))
                            ->required()
                            ->helperText(__('company.fields.fiscal_day_hint')),

                        Toggle::make('uses_hijri_fiscal_year')
                            ->label(__('company.fields.uses_hijri_fiscal_year'))
                            ->helperText(__('company.fields.hijri_hint')),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $this->company()->update($this->form->getState());

        Notification::make()
            ->title(__('company.settings.saved'))
            ->success()
            ->send();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function company(): Company
    {
        return CurrentCompany::get();
    }

    /**
     * Sequential options from one to a maximum, keyed and labelled as strings.
     *
     * array_combine over two integer ranges produces array<int, int>, which is
     * not the shape a select expects; the labels must be strings or the option
     * renders as an integer and comparisons against the stored value drift.
     *
     * @return array<int, string>
     */
    private static function numericOptions(int $max): array
    {
        $options = [];

        for ($value = 1; $value <= $max; $value++) {
            $options[$value] = (string) $value;
        }

        return $options;
    }
}
