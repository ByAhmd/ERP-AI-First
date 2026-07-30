<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Trading locations.
 *
 * A branch is a real entity rather than an analytical tag: later phases attach
 * inventory balances, point-of-sale terminals and ZATCA branch reporting to it.
 * That is why it stays a column on the journal line while everything analytical
 * moved to dimensions.
 */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('accounting.branches.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.branches.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('code')
                        ->label(__('accounting.branches.columns.code'))
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true),

                    TextInput::make('name')
                        ->label(__('accounting.branches.columns.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('accounting.branches.columns.name_en'))
                        ->maxLength(255),

                    Toggle::make('is_active')
                        ->label(__('accounting.branches.columns.active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('accounting.branches.columns.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('accounting.branches.columns.name'))
                    ->searchable()
                    ->description(fn (Branch $record): ?string => $record->name_en),

                IconColumn::make('is_active')
                    ->label(__('accounting.branches.columns.active'))
                    ->boolean(),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
