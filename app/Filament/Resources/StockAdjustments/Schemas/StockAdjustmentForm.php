<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentKind;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCost;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The stock adjustment form.
 *
 * The lines take the signed delta; the current quantity shows beside each
 * product as the reference figure. Unit cost matters only on increases —
 * decreases are valued at the running average at approval, whatever was
 * typed. The offset account hides for openings, which the poster forces to
 * the opening-balance suspense regardless.
 */
class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('inventory.adjustments.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('inventory.adjustments.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('kind')
                        ->label(__('inventory.adjustments.fields.kind'))
                        ->options(StockAdjustmentKind::class)
                        ->default(StockAdjustmentKind::Count)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),

                    Select::make('branch_id')
                        ->label(__('inventory.adjustments.fields.branch'))
                        ->options(fn (): array => Branch::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Branch $b): array => [
                                $b->getKey() => $b->displayName(),
                            ])
                            ->all())
                        ->default(fn (): ?string => Branch::query()
                            ->where('is_default', true)->value('id'))
                        ->required(),

                    DatePicker::make('adjustment_date')
                        ->label(__('inventory.adjustments.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    Select::make('offset_account_id')
                        ->label(__('inventory.adjustments.fields.offset_account'))
                        ->helperText(__('inventory.adjustments.hints.offset_account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_postable', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->code.' - '.$a->name,
                            ])
                            ->all())
                        ->default(fn (): ?string => Account::query()
                            ->where('system_key', SystemAccount::InventoryAdjustment->value)
                            ->value('id'))
                        ->searchable()
                        ->visible(fn (Get $get): bool => self::kindOf($get('kind')) === StockAdjustmentKind::Count),

                    TextInput::make('description')
                        ->label(__('inventory.adjustments.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('inventory.adjustments.sections.items'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('inventory.adjustments.items.product'))->width('40%'),
                            TableColumn::make(__('inventory.adjustments.items.quantity_change'))->width('30%')->alignEnd(),
                            TableColumn::make(__('inventory.adjustments.items.unit_cost'))->width('30%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('product_id')
                                ->options(fn (): array => Product::query()
                                    ->where('track_inventory', true)
                                    ->orderBy('sku')
                                    ->get()
                                    ->mapWithKeys(fn (Product $p): array => [
                                        $p->getKey() => $p->sku.' — '.$p->displayName(),
                                    ])
                                    ->all())
                                ->searchable()
                                ->required()
                                ->live()
                                // The reference figure beside the line —
                                // advisory; the binding check runs under the
                                // lock at approval.
                                ->helperText(function (Get $get): string {
                                    $productId = $get('product_id');

                                    if (blank($productId)) {
                                        return '';
                                    }

                                    $quantity = ProductCost::query()
                                        ->where('product_id', $productId)
                                        ->value('quantity_on_hand');

                                    return __('inventory.stock.available_hint', [
                                        'quantity' => number_format((float) ($quantity ?? 0), 2),
                                    ]);
                                }),

                            TextInput::make('quantity_change')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true)
                                ->helperText(__('inventory.adjustments.hints.quantity_change')),

                            TextInput::make('unit_cost')
                                ->numeric()
                                ->minValue(0)
                                ->requiredIf('quantity_change', '>0')
                                ->visible(fn (Get $get): bool => ! is_numeric($get('quantity_change'))
                                    || (float) $get('quantity_change') > 0),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('inventory.adjustments.items.add'))
                        ->reorderable(false),
                ]),
        ]);
    }

    private static function kindOf(mixed $state): StockAdjustmentKind
    {
        if ($state instanceof StockAdjustmentKind) {
            return $state;
        }

        return is_string($state)
            ? StockAdjustmentKind::tryFrom($state) ?? StockAdjustmentKind::Count
            : StockAdjustmentKind::Count;
    }
}
