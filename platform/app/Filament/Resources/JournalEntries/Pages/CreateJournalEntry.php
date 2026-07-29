<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    /**
     * New entries are created as drafts.
     *
     * Posting is a separate, deliberate act — it consumes a number, resolves
     * the period and freezes the record. Making "save" mean "post" would leave
     * a user one mistyped figure away from a permanent ledger entry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        // Placeholder occupying the unique index until posting allocates a real
        // number. Drafts must not consume the series.
        $data['number'] = 'DRAFT-'.strtoupper(Str::ulid()->toBase32());

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
