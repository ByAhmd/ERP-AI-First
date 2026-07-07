import { SetMetadata } from '@nestjs/common';
import { PermissionKey } from '@erp-ai/shared';

export const PERMISSIONS_KEY = 'permissions';

/**
 * Enforces that the authenticated user possesses ALL of the specified permissions
 * within the current tenant context.
 */
export const RequirePermissions = (...permissions: PermissionKey[]) =>
  SetMetadata(PERMISSIONS_KEY, permissions);
