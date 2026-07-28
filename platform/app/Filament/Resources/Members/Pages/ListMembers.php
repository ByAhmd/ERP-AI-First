<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Services\Identity\Exceptions\InvitationFailed;
use App\Services\Identity\InvitationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
        ];
    }

    /**
     * Invitation rather than creation.
     *
     * An administrator cannot set another person's password, so a member record
     * is never written directly. The invitee sets their own credential on
     * acceptance, which also proves control of the mailbox.
     */
    private function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('identity.members.actions.invite'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->schema([
                TextInput::make('email')
                    ->label(__('identity.members.columns.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('name')
                    ->label(__('identity.members.columns.name'))
                    ->required()
                    ->maxLength(255),

                Select::make('role_id')
                    ->label(__('identity.members.columns.role'))
                    ->options(fn (): array => $this->availableRoles())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, InvitationService $invitations): void {
                try {
                    $invitations->invite(
                        company: Filament::getTenant(),
                        email: $data['email'],
                        name: $data['name'],
                        role: Role::find($data['role_id']),
                        invitedBy: Filament::auth()->user(),
                    );
                } catch (InvitationFailed $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('identity.members.notifications.invitation_sent'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Roles belonging to the current company only.
     *
     * Roles are company-scoped; listing them unfiltered would offer another
     * company's roles for assignment.
     *
     * @return array<string, string>
     */
    private function availableRoles(): array
    {
        $companyId = Filament::getTenant()?->getKey();

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        return Role::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
