<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Tables;

use App\Enums\CompanyMembershipStatus;
use App\Filament\Support\CurrentCompany;
use App\Models\CompanyUser;
use App\Services\Identity\Exceptions\InvitationFailed;
use App\Services\Identity\Exceptions\MembershipChangeRejected;
use App\Services\Identity\InvitationService;
use App\Services\Identity\MembershipService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('identity.members.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label(__('identity.members.columns.email'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->label(__('identity.members.columns.status'))
                    ->badge(),

                TextColumn::make('joined_at')
                    ->label(__('identity.members.columns.joined_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('invitation_expires_at')
                    ->label(__('identity.members.columns.invitation_expires_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('invitedBy.name')
                    ->label(__('identity.members.columns.invited_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('identity.members.columns.status'))
                    ->options(fn (): array => collect(CompanyMembershipStatus::cases())
                        ->mapWithKeys(fn (CompanyMembershipStatus $case): array => [
                            $case->value => $case->getLabel(),
                        ])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('resendInvitation')
                    ->label(__('identity.members.actions.resend'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (CompanyUser $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->action(function (CompanyUser $record, InvitationService $invitations): void {
                        // Reissues a fresh token; the previous one stops working,
                        // so a forwarded old link cannot be redeemed later.
                        $invitations->invite(
                            company: CurrentCompany::get(),
                            email: $record->user->email,
                            name: $record->user->name,
                            invitedBy: Filament::auth()->user(),
                        );

                        Notification::make()
                            ->title(__('identity.members.notifications.invitation_resent'))
                            ->success()
                            ->send();
                    }),

                Action::make('revokeInvitation')
                    ->label(__('identity.members.actions.revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (CompanyUser $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->action(function (CompanyUser $record, InvitationService $invitations): void {
                        try {
                            $invitations->revoke($record);
                        } catch (InvitationFailed $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('identity.members.notifications.invitation_revoked'))
                            ->success()
                            ->send();
                    }),

                Action::make('suspend')
                    ->label(__('identity.members.actions.suspend'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (CompanyUser $record): bool => $record->status === CompanyMembershipStatus::Active
                        && ! self::isSelf($record))
                    ->requiresConfirmation()
                    ->action(function (CompanyUser $record, MembershipService $memberships): void {
                        try {
                            $memberships->suspend($record, Filament::auth()->id());
                        } catch (MembershipChangeRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('identity.members.notifications.suspended'))
                            ->success()
                            ->send();
                    }),

                Action::make('reinstate')
                    ->label(__('identity.members.actions.reinstate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (CompanyUser $record): bool => $record->status === CompanyMembershipStatus::Suspended)
                    ->requiresConfirmation()
                    ->action(function (CompanyUser $record, MembershipService $memberships): void {
                        try {
                            $memberships->reinstate($record);
                        } catch (MembershipChangeRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('identity.members.notifications.reinstated'))
                            ->success()
                            ->send();
                    }),
            ])
            // No bulk delete. Removing membership in bulk is how an administrator
            // accidentally locks a company out of its own books.
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Guards against an administrator suspending their own access and locking
     * themselves out of the company.
     */
    private static function isSelf(CompanyUser $record): bool
    {
        return $record->user_id === Filament::auth()->id();
    }
}
