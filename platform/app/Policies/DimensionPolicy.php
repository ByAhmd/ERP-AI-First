<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dimension;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DimensionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Dimension');
    }

    public function view(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('View:Dimension');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Dimension');
    }

    public function update(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('Update:Dimension');
    }

    public function delete(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('Delete:Dimension');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Dimension');
    }

    public function restore(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('Restore:Dimension');
    }

    public function forceDelete(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('ForceDelete:Dimension');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Dimension');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Dimension');
    }

    public function replicate(AuthUser $authUser, Dimension $dimension): bool
    {
        return $authUser->can('Replicate:Dimension');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Dimension');
    }
}
