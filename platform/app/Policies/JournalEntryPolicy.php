<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\JournalEntry;
use Illuminate\Auth\Access\HandlesAuthorization;

class JournalEntryPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:JournalEntry');
    }

    public function view(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('View:JournalEntry');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:JournalEntry');
    }

    public function update(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('Update:JournalEntry');
    }

    public function delete(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('Delete:JournalEntry');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:JournalEntry');
    }

    public function restore(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('Restore:JournalEntry');
    }

    public function forceDelete(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('ForceDelete:JournalEntry');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:JournalEntry');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:JournalEntry');
    }

    public function replicate(AuthUser $authUser, JournalEntry $journalEntry): bool
    {
        return $authUser->can('Replicate:JournalEntry');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:JournalEntry');
    }

}