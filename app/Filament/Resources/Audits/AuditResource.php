<?php

declare(strict_types=1);

namespace App\Filament\Resources\Audits;

use App\Filament\Resources\Audits\Pages\ListAudits;
use App\Filament\Resources\Audits\Tables\AuditsTable;
use App\Models\Audit;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit trail, read only.
 *
 * Records are never created, edited or deleted through the panel — an audit
 * trail that can be altered from the interface it audits provides no assurance.
 * Retention is handled by a scheduled job, not by hand.
 */
class AuditResource extends Resource
{
    protected static ?string $model = Audit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 95;

    /**
     * Scoped explicitly.
     *
     * Audit rows carry `company_id` but deliberately sit outside
     * BelongsToCompany, because they are written by an observer that may run in
     * any context. Reads are therefore filtered here, and the filter is the only
     * thing standing between one company and another's change history.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()?->getKey());
    }

    public static function getModelLabel(): string
    {
        return __('audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation_group');
    }

    public static function table(Table $table): Table
    {
        return AuditsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudits::route('/'),
        ];
    }
}
