<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Company;
use App\Services\Accounting\ChartOfAccountsTemplate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->applyTemplateAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Restore anything the template provides that this company lacks.
     *
     * Safe to run at any time: existing accounts, including renamed ones, are
     * left untouched. Its real purpose is recovery — a company whose chart was
     * built by hand may be missing accounts the posting logic needs.
     */
    private function applyTemplateAction(): Action
    {
        return Action::make('applyTemplate')
            ->label(__('accounting.accounts.actions.apply_template'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('accounting.accounts.actions.apply_template_hint'))
            ->action(function (ChartOfAccountsTemplate $template): void {
                /** @var Company $company */
                $company = Filament::getTenant();

                $created = $template->applyTo($company);

                Notification::make()
                    ->title($created > 0
                        ? __('accounting.accounts.notifications.template_applied', ['count' => $created])
                        : __('accounting.accounts.notifications.template_complete'))
                    ->success()
                    ->send();
            });
    }
}
