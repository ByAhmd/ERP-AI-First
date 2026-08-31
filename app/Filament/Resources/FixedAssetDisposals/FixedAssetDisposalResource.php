<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetDisposals;

use App\Enums\AssetDisposalKind;
use App\Enums\DocumentStatus;
use App\Filament\Resources\FixedAssetDisposals\Pages\CreateFixedAssetDisposal;
use App\Filament\Resources\FixedAssetDisposals\Pages\EditFixedAssetDisposal;
use App\Filament\Resources\FixedAssetDisposals\Pages\ListFixedAssetDisposals;
use App\Filament\Resources\FixedAssetDisposals\Pages\ViewFixedAssetDisposal;
use App\Filament\Resources\FixedAssetDisposals\Schemas\FixedAssetDisposalForm;
use App\Models\FixedAssetDisposal;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Disposals — الاستبعادات: بيع and تخريد behind one lifecycle.
 *
 * Drafts are edited; approval depreciates to the disposal date, posts the
 * disposal entry and freezes everything. No un-dispose, no delete of an
 * approved disposal.
 */
class FixedAssetDisposalResource extends Resource
{
    protected static ?string $model = FixedAssetDisposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('assets.disposals.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assets.disposals.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('assets.disposals.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('assets.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetDisposalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['asset']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('assets.disposals.columns.reference'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('kind')
                    ->label(__('assets.disposals.columns.kind'))
                    ->badge(),

                TextColumn::make('asset.name')
                    ->label(__('assets.disposals.columns.asset')),

                TextColumn::make('disposal_date')
                    ->label(__('assets.disposals.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('proceeds')
                    ->label(__('assets.disposals.columns.proceeds'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('gain_loss_amount')
                    ->label(__('assets.disposals.columns.gain_loss'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn (FixedAssetDisposal $record): string => $record->gain_loss_amount !== null
                        && bccomp((string) $record->gain_loss_amount, '0', 4) < 0
                        ? 'danger'
                        : 'success'),

                TextColumn::make('status')
                    ->label(__('assets.disposals.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('assets.disposals.columns.kind'))
                    ->options(AssetDisposalKind::class),

                SelectFilter::make('status')
                    ->label(__('assets.disposals.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (FixedAssetDisposal $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (FixedAssetDisposal $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssetDisposals::route('/'),
            'create' => CreateFixedAssetDisposal::route('/create'),
            'edit' => EditFixedAssetDisposal::route('/{record}/edit'),
            'view' => ViewFixedAssetDisposal::route('/{record}'),
        ];
    }
}
