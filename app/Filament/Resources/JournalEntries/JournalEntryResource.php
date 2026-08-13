<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries;

use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Resources\JournalEntries\Schemas\JournalEntryForm;
use App\Filament\Resources\JournalEntries\Tables\JournalEntriesTable;
use App\Models\JournalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Journal entries.
 *
 * Only drafts are editable. A posted entry opens read-only, with reversal as
 * the sole route to a correction — the same rule the observer enforces, made
 * visible so a user is never offered an action that will be refused.
 */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('accounting.entries.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.entries.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return JournalEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalEntriesTable::configure($table);
    }

    /**
     * Posted entries are immutable, so the edit screen is offered only for
     * drafts. Filament hides the action rather than failing on save.
     */
    public static function canEdit(mixed $record): bool
    {
        return $record instanceof JournalEntry
            && $record->isDraft()
            && parent::canEdit($record);
    }

    public static function canDelete(mixed $record): bool
    {
        return $record instanceof JournalEntry
            && $record->isDraft()
            && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'view' => ViewJournalEntry::route('/{record}'),
            'edit' => EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
