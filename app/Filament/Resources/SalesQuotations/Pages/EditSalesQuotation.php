<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesQuotations\Pages;

use App\Filament\Resources\SalesQuotations\SalesQuotationResource;
use App\Models\SalesQuotation;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use App\Services\Sales\SalesQuotationApprover;
use App\Services\Sales\SalesQuotationRecalculator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSalesQuotation extends EditRecord
{
    protected static string $resource = SalesQuotationResource::class;

    /**
     * Anything past draft is not editable.
     *
     * Approved is the offer the customer holds; invoiced is frozen provenance
     * for the invoice pointing at it. Redirecting rather than refusing,
     * because the user's intent — to look at the quotation — is reasonable.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SalesQuotation $quotation */
        $quotation = $this->getRecord();

        if (! $quotation->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $quotation]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Qoyod's حفظ وموافقة — but unlike the invoice's, the modal says
            // what approval does NOT do: no accounts move, no entry posts.
            Action::make('approve')
                ->label(__('sales.quotations.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('sales.quotations.actions.approve_confirm'))
                ->action(function (SalesQuotationApprover $approver): void {
                    // Save first: approving fixes what is stored, and an
                    // unsaved edit would otherwise be fixed in its old form.
                    $this->save(shouldRedirect: false);

                    /** @var SalesQuotation $quotation */
                    $quotation = $this->getRecord()->refresh();

                    try {
                        $approved = $approver->approve($quotation, Filament::auth()->id());
                    } catch (QuotationRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.quotations.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Totals are derived from the lines, never taken from the form.
     */
    protected function afterSave(): void
    {
        /** @var SalesQuotation $quotation */
        $quotation = $this->getRecord();

        app(SalesQuotationRecalculator::class)->recalculate($quotation);
    }

    /**
     * Qoyod's حفظ كمسودة — an ordinary save.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('sales.quotations.actions.save_draft'));
    }
}
