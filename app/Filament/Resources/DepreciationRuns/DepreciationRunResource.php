<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationRuns;

use App\Enums\DocumentStatus;
use App\Filament\Resources\DepreciationRuns\Pages\ListDepreciationRuns;
use App\Filament\Resources\DepreciationRuns\Pages\ViewDepreciationRun;
use App\Models\DepreciationRun;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\Exceptions\RunRejected;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Depreciation runs — الإهلاك.
 *
 * Runs are born approved and never edited; the one backward step is the
 * reversal action, which drops the ledger money and the charge rows
 * together, so a later run may legitimately re-claim the periods.
 */
class DepreciationRunResource extends Resource
{
    protected static ?string $model = DepreciationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('assets.runs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assets.runs.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('assets.runs.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('assets.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('reference')
                ->label(__('assets.runs.columns.reference'))
                ->disabled(),

            TextInput::make('through_date')
                ->label(__('assets.runs.columns.through_date'))
                ->disabled(),

            TextInput::make('assets_count')
                ->label(__('assets.runs.columns.assets_count'))
                ->disabled(),

            TextInput::make('total_amount')
                ->label(__('assets.runs.columns.total_amount'))
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['throughPeriod', 'journalEntry']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('assets.runs.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('throughPeriod.name')
                    ->label(__('assets.runs.columns.period')),

                TextColumn::make('through_date')
                    ->label(__('assets.runs.columns.through_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('assets_count')
                    ->label(__('assets.runs.columns.assets_count'))
                    ->alignEnd(),

                TextColumn::make('total_amount')
                    ->label(__('assets.runs.columns.total_amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('journalEntry.number')
                    ->label(__('assets.runs.columns.entry'))
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('assets.runs.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('assets.runs.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),

                self::reverseAction(),
            ]);
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label(__('assets.runs.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (DepreciationRun $record): bool => $record->isApproved())
            ->requiresConfirmation()
            ->modalDescription(__('assets.runs.actions.reverse_confirm'))
            ->schema([
                DatePicker::make('date')
                    ->label(__('assets.runs.actions.reversal_date'))
                    ->native(false)
                    ->default(now())
                    ->required(),
            ])
            ->action(function (DepreciationRun $record, array $data, DepreciationEngine $engine): void {
                try {
                    $engine->reverse(
                        $record,
                        CarbonImmutable::parse($data['date']),
                        Filament::auth()->id(),
                    );
                } catch (RunRejected|PostingRejected $refusal) {
                    Notification::make()
                        ->title($refusal->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('assets.runs.actions.reversed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\RunChargesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDepreciationRuns::route('/'),
            'view' => ViewDepreciationRun::route('/{record}'),
        ];
    }
}
