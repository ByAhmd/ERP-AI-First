<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\PurchaseOrder;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use App\Services\Purchases\PurchaseOrderConverter;
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
 * The purchase orders list.
 *
 * The overdue signal lives on the expiry date column, derived from the
 * clock — Qoyod's متأخرة, which a stored status could only fake with a
 * scheduler. The status badge always tells the stored truth.
 */
class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('purchases.orders.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('purchases.orders.columns.contact'))
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('purchases.orders.columns.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label(__('purchases.orders.columns.expiry_date'))
                    ->date('d M Y')
                    ->sortable()
                    ->color(fn (PurchaseOrder $record): ?string => $record->isOverdue() ? 'danger' : null),

                TextColumn::make('subtotal_net')
                    ->label(__('purchases.orders.columns.net'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('tax_total')
                    ->label(__('purchases.orders.columns.tax'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label(__('purchases.orders.columns.total'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('purchases.orders.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('purchases.orders.columns.status'))
                    ->options(PurchaseOrderStatus::class),

                Filter::make('overdue')
                    ->label(__('purchases.orders.filters.overdue'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('expiry_date', '<', today())
                        ->whereIn('status', [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved])),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => ! $record->isDraft()),

                self::convertAction(),
            ]);
    }

    /**
     * تحويل لفاتورة — from the row menu, as Qoyod offers it. The view page
     * carries the same action; the converter's lock decides any race.
     */
    public static function convertAction(): Action
    {
        return Action::make('convert')
            ->label(__('purchases.orders.actions.convert'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('primary')
            ->visible(fn (PurchaseOrder $record): bool => $record->isApproved())
            ->requiresConfirmation()
            ->modalDescription(fn (PurchaseOrder $record): string => $record->isOverdue()
                ? __('purchases.orders.actions.convert_overdue_warning', [
                    'date' => $record->expiry_date->format('d M Y'),
                ])
                : __('purchases.orders.actions.convert_confirm'))
            ->action(function (PurchaseOrder $record, mixed $livewire): void {
                try {
                    $invoice = app(PurchaseOrderConverter::class)
                        ->convert($record, Filament::auth()->id());
                } catch (PurchaseOrderRuleViolation $refusal) {
                    Notification::make()
                        ->title($refusal->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('purchases.orders.actions.converted', [
                        'reference' => $invoice->reference,
                    ]))
                    ->success()
                    ->send();

                // The pre-filled bill, open for review before approval.
                $livewire->redirect(
                    PurchaseInvoiceResource::getUrl('edit', ['record' => $invoice]),
                );
            });
    }
}
