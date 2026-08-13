<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A posted entry, read only.
 *
 * The form is deliberately not reused here. A posted entry is immutable, and
 * presenting editable fields would offer an action the ledger will refuse.
 */
class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('accounting.entries.sections.header'))
                ->schema([
                    TextEntry::make('number')
                        ->label(__('accounting.entries.columns.number')),
                    TextEntry::make('entry_date')
                        ->label(__('accounting.entries.columns.date'))
                        ->date(),
                    TextEntry::make('status')
                        ->label(__('accounting.entries.columns.status'))
                        ->badge(),
                    TextEntry::make('reference')
                        ->label(__('accounting.entries.columns.reference'))
                        ->placeholder('—'),
                    TextEntry::make('description')
                        ->label(__('accounting.entries.columns.description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Section::make(__('accounting.entries.sections.lines'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('account.code')
                                ->label(__('accounting.entries.columns.account')),
                            TextEntry::make('account.name')
                                ->hiddenLabel()
                                ->columnSpan(2),
                            TextEntry::make('debit')
                                ->label(__('accounting.normal_balance.debit'))
                                ->numeric(decimalPlaces: 2),
                            TextEntry::make('credit')
                                ->label(__('accounting.normal_balance.credit'))
                                ->numeric(decimalPlaces: 2),
                            TextEntry::make('description')
                                ->label(__('accounting.entries.columns.line_description'))
                                ->placeholder('—'),
                        ])
                        ->columns(6),
                ]),

            Section::make(__('accounting.entries.sections.totals'))
                ->schema([
                    TextEntry::make('total_debit')
                        ->label(__('accounting.entries.columns.total_debit'))
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('total_credit')
                        ->label(__('accounting.entries.columns.total_credit'))
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('postedBy.name')
                        ->label(__('accounting.entries.columns.posted_by'))
                        ->placeholder('—'),
                    TextEntry::make('posted_at')
                        ->label(__('accounting.entries.columns.posted_at'))
                        ->dateTime()
                        ->placeholder('—'),

                    // Both directions of the reversal pairing, so the
                    // correction and the thing corrected are reachable from
                    // either side.
                    TextEntry::make('reverses.number')
                        ->label(__('accounting.entries.columns.reverses'))
                        ->placeholder('—')
                        ->url(fn (JournalEntry $record): ?string => $record->reverses
                            ? static::getResource()::getUrl('view', ['record' => $record->reverses])
                            : null),

                    TextEntry::make('reversedBy.number')
                        ->label(__('accounting.entries.columns.reversed_by'))
                        ->placeholder('—')
                        ->url(fn (JournalEntry $record): ?string => $record->reversedBy
                            ? static::getResource()::getUrl('view', ['record' => $record->reversedBy])
                            : null),
                ])
                ->columns(3),
        ]);
    }
}
