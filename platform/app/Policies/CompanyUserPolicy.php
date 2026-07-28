<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CompanyUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class CompanyUserPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CompanyUser');
    }

    public function view(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('View:CompanyUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CompanyUser');
    }

    public function update(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('Update:CompanyUser');
    }

    public function delete(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('Delete:CompanyUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CompanyUser');
    }

    public function restore(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('Restore:CompanyUser');
    }

    public function forceDelete(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('ForceDelete:CompanyUser');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CompanyUser');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CompanyUser');
    }

    public function replicate(AuthUser $authUser, CompanyUser $companyUser): bool
    {
        return $authUser->can('Replicate:CompanyUser');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CompanyUser');
    }

}