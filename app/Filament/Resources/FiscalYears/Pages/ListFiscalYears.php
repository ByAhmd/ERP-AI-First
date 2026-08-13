<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Models\Company;
use App\Services\Accounting\FiscalCalendar;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(__('accounting.fiscal_years.actions.generate'))
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('year')
                        ->label(__('accounting.fiscal_years.actions.starting_year'))
                        ->numeric()
                        ->minValue(2000)
                        ->maxValue(2100)
                        ->default(now()->year)
                        ->required()
                        ->helperText(__('accounting.fiscal_years.actions.starting_year_hint')),
                ])
                ->action(function (array $data, FiscalCalendar $calendar): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    try {
                        $year = $calendar->createYear($company, (int) $data['year']);
                    } catch (Throwable $e) {
                        // Most often a duplicate — the year already exists.
                        Notification::make()
                            ->title(__('accounting.fiscal_years.notifications.generate_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('accounting.fiscal_years.notifications.generated', ['name' => $year->name]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
