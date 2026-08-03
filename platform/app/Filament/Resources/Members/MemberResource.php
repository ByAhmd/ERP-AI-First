<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Schemas\MemberForm;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Models\CompanyUser;
use App\Models\Scopes\CompanyScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Company membership administration.
 *
 * Operates on {@see CompanyUser} rather than User because what is administered
 * here is membership — invited, active, suspended — not the person. The same
 * person may belong to several companies with a different role in each.
 *
 * There is no create page. Members arrive by invitation, which issues a
 * credential and is therefore a service operation, not a form write.
 */
class MemberResource extends Resource
{
    protected static ?string $model = CompanyUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /**
     * Filament's tenant scoping, declared as a second layer.
     *
     * The authoritative guard is the application's own {@see CompanyScope},
     * which CompanyUser carries like every other tenant-owned table. Filament's
     * documentation is explicit that its scoping applies only after tenant
     * identification in panel middleware and that multi-tenant security remains
     * the application's responsibility — so it is used here in addition to, not
     * instead of, the global scope.
     *
     * Note the distinction: ownership (record → company) is what filters
     * queries; the relationship (company → records) is what associates new ones.
     */
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?string $tenantRelationshipName = 'memberships';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('identity.members.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.members.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
