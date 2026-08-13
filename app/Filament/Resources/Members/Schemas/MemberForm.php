<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Schemas;

use App\Enums\CompanyMembershipStatus;
use App\Models\CompanyUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('identity.members.sections.person'))
                ->description(__('identity.members.sections.person_hint'))
                ->schema([
                    // Read-only: the person owns their own name and email, and
                    // changing an email here would silently move an invitation
                    // to a different mailbox.
                    Placeholder::make('user_name')
                        ->label(__('identity.members.columns.name'))
                        ->content(fn (?CompanyUser $record): string => $record?->user->name ?? '—'),

                    Placeholder::make('user_email')
                        ->label(__('identity.members.columns.email'))
                        ->content(fn (?CompanyUser $record): string => $record?->user->email ?? '—'),
                ])
                ->columns(2),

            Section::make(__('identity.members.sections.access'))
                ->schema([
                    Select::make('status')
                        ->label(__('identity.members.columns.status'))
                        ->options(collect(CompanyMembershipStatus::cases())
                            ->mapWithKeys(fn (CompanyMembershipStatus $case): array => [
                                $case->value => $case->getLabel(),
                            ])
                            ->all())
                        ->required()
                        // Invited is reached by issuing an invitation, not by
                        // selecting it — doing so would leave a membership
                        // pending with no token to accept.
                        ->disableOptionWhen(
                            fn (string $value): bool => $value === CompanyMembershipStatus::Invited->value,
                        )
                        ->helperText(__('identity.members.hints.status')),

                    Select::make('roles')
                        ->label(__('identity.members.columns.role'))
                        ->relationship(
                            name: 'user.roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->where(
                                'company_id',
                                Filament::getTenant()?->getKey(),
                            ),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->name)
                        ->multiple()
                        ->preload()
                        ->helperText(__('identity.members.hints.role')),
                ])
                ->columns(2),
        ]);
    }
}
