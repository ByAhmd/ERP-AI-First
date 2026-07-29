<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FiscalYear;
use Illuminate\Auth\Access\HandlesAuthorization;

class FiscalYearPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FiscalYear');
    }

    public function view(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('View:FiscalYear');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FiscalYear');
    }

    public function update(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('Update:FiscalYear');
    }

    public function delete(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('Delete:FiscalYear');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FiscalYear');
    }

    public function restore(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('Restore:FiscalYear');
    }

    public function forceDelete(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('ForceDelete:FiscalYear');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FiscalYear');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FiscalYear');
    }

    public function replicate(AuthUser $authUser, FiscalYear $fiscalYear): bool
    {
        return $authUser->can('Replicate:FiscalYear');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FiscalYear');
    }

}