<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoices\Pages;

use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Services\Sales\Exceptions\InvoiceRuleViolation;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    /**
     * An approved invoice is not editable.
     *
     * The table offers it a view action rather than an edit one, but a URL can
     * be typed. Redirecting rather than refusing, because the user's intent —
     * to look at the invoice — is perfectly reasonable.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SalesInvoice $invoice */
        $invoice = $this->getRecord();

        if (! $invoice->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $invoice]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Qoyod's حفظ وموافقة. Its own help states what this does: the
            // invoice becomes final, appears in reports and affects the
            // accounts.
            Action::make('approve')
                ->label(__('sales.invoices.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('sales.invoices.actions.approve_confirm'))
                ->action(function (SalesInvoicePoster $poster): void {
                    // Save first: approving reads what is stored, and an unsaved
                    // edit would otherwise be posted in its old form.
                    $this->save(shouldRedirect: false);

                    /** @var SalesInvoice $invoice */
                    $invoice = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($invoice, Filament::auth()->id());
                    } catch (InvoiceRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.invoices.actions.approved'))
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
        /** @var SalesInvoice $invoice */
        $invoice = $this->getRecord();

        app(SalesInvoiceRecalculator::class)->recalculate($invoice);
    }

    /**
     * Qoyod's حفظ كمسودة — an ordinary save, which affects nothing.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('sales.invoices.actions.save_draft'));
    }
}
