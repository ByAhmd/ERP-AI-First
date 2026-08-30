<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesQuotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Models\SalesQuotation;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use App\Services\Sales\QuotationConverter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The quotations list.
 *
 * No outstanding-balance decoration and no payment column: nothing is owed on
 * a quotation. The signal this list carries instead is validity — the expiry
 * date turns red once the offer has lapsed, while the status badge keeps
 * telling the stored truth.
 */
class SalesQuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('sales.quotations.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('sales.quotations.columns.contact'))
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('sales.quotations.columns.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label(__('sales.quotations.columns.expiry_date'))
                    ->date('d M Y')
                    ->sortable()
                    // The expired signal lives here, derived from the clock;
                    // an expired status would need a scheduler to stay true.
                    ->color(fn (SalesQuotation $record): ?string => $record->isExpired() ? 'danger' : null),

                TextColumn::make('subtotal_net')
                    ->label(__('sales.quotations.columns.net'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('tax_total')
                    ->label(__('sales.quotations.columns.tax'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label(__('sales.quotations.columns.total'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('sales.quotations.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('sales.quotations.columns.status'))
                    ->options(QuotationStatus::class),

                Filter::make('expired')
                    ->label(__('sales.quotations.filters.expired'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('expiry_date', '<', today())
                        ->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Approved])),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (SalesQuotation $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (SalesQuotation $record): bool => ! $record->isDraft()),

                self::convertAction(),
            ]);
    }

    /**
     * تحويل لفاتورة — Qoyod offers it from the row menu, so this list does too.
     *
     * The view page carries the same action; both call the same converter, and
     * the converter's lock decides any race between them.
     */
    public static function convertAction(): Action
    {
        return Action::make('convert')
            ->label(__('sales.quotations.actions.convert'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('primary')
            ->visible(fn (SalesQuotation $record): bool => $record->isApproved())
            ->requiresConfirmation()
            ->modalDescription(fn (SalesQuotation $record): string => $record->isExpired()
                ? __('sales.quotations.actions.convert_expired_warning', [
                    'date' => $record->expiry_date->format('d M Y'),
                ])
                : __('sales.quotations.actions.convert_confirm'))
            ->action(function (SalesQuotation $record, mixed $livewire): void {
                try {
                    $invoice = app(QuotationConverter::class)
                        ->convert($record, Filament::auth()->id());
                } catch (QuotationRuleViolation $refusal) {
                    Notification::make()
                        ->title($refusal->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('sales.quotations.actions.converted', [
                        'reference' => $invoice->reference,
                    ]))
                    ->success()
                    ->send();

                // Qoyod lands you on the pre-filled invoice to review; the
                // draft's edit page is this codebase's version of that.
                $livewire->redirect(
                    SalesInvoiceResource::getUrl('edit', ['record' => $invoice]),
                );
            });
    }
}
