<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryTransfers\Schemas;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The transfer form — Qoyod's نقل المخزون field set, one-step.
 *
 * The available quantity beside each line reads the SOURCE branch, because
 * that is what physically ships; it is advisory, and the binding check runs
 * under the lock at approval.
 */
class InventoryTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('inventory.transfers.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('inventory.transfers.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('from_branch_id')
                        ->label(__('inventory.transfers.fields.from_branch'))
                        ->options(fn (): array => self::branches())
                        ->default(fn (): ?string => Branch::query()
                            ->where('is_default', true)->value('id'))
                        ->required()
                        ->live()
                        ->different('to_branch_id'),

                    Select::make('to_branch_id')
                        ->label(__('inventory.transfers.fields.to_branch'))
                        ->options(fn (): array => self::branches())
                        ->required()
                        ->live()
                        ->different('from_branch_id'),

                    DatePicker::make('transfer_date')
                        ->label(__('inventory.transfers.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('description')
                        ->label(__('inventory.transfers.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('inventory.transfers.sections.items'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('inventory.transfers.items.product'))->width('60%'),
                            TableColumn::make(__('inventory.transfers.items.quantity'))->width('40%')->alignEnd(),
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
                                ->helperText(function (Get $get): string {
                                    $productId = $get('product_id');
                                    $branchId = $get('../../from_branch_id');

                                    if (blank($productId) || blank($branchId)) {
                                        return '';
                                    }

                                    $quantity = ProductStock::query()
                                        ->where('product_id', $productId)
                                        ->where('branch_id', $branchId)
                                        ->value('quantity_on_hand');

                                    return __('inventory.stock.available_hint', [
                                        'quantity' => number_format((float) ($quantity ?? 0), 2),
                                    ]);
                                }),

                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('inventory.transfers.items.add'))
                        ->reorderable(false),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function branches(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Branch $b): array => [
                $b->getKey() => $b->displayName(),
            ])
            ->all();
    }
}
