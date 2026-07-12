import { CanActivate, ExecutionContext, Injectable, ForbiddenException } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { PermissionKey } from '@erp-ai/shared';
import { PrismaService } from '../../database/prisma.service';
import { PERMISSIONS_KEY } from '../decorators/require-permissions.decorator';
import { RequestUser } from '../decorators/current-user.decorator';

@Injectable()
export class PermissionsGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly prisma: PrismaService,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const requiredPermissions = this.reflector.getAllAndOverride<PermissionKey[]>(
      PERMISSIONS_KEY,
      [context.getHandler(), context.getClass()],
    );

    if (!requiredPermissions || requiredPermissions.length === 0) {
      return true; // No permissions required
    }

    const request = context.switchToHttp().getRequest();
    const user = request.user as RequestUser | undefined;
    const tenantId = user?.tenantId;

    if (!user || !tenantId) {
      throw new ForbiddenException('Tenant context is required for this action');
    }

    console.log(`[PermissionsGuard] Checking access for user=${user.id}, tenant=${tenantId}`);

    // Determine the user's role in this tenant
    const tenantUser = await this.prisma.tenantUser.findUnique({
      where: {
        tenantId_userId: {
          tenantId,
          userId: user.id,
        },
      },
      include: {
        role: {
          include: {
            rolePermissions: {
              include: {
                permission: true,
              },
            },
          },
        },
      },
    });

    console.log(`[PermissionsGuard] Result:`, tenantUser ? `Found (status: ${tenantUser.status}, role: ${tenantUser.roleId})` : 'NOT FOUND');

    if (!tenantUser || tenantUser.status !== 'Active') {
      throw new ForbiddenException('User is not active in this tenant');
    }

    if (!tenantUser.role) {
      throw new ForbiddenException('User has no role assigned in this tenant');
    }

    // Extract the keys of the permissions the user has
    const userPermissions = tenantUser.role.rolePermissions.map((rp) => rp.permission.key);

    // Check if the user has ALL required permissions
    const hasAllPermissions = requiredPermissions.every((requiredPerm) =>
      userPermissions.includes(requiredPerm),
    );

    if (!hasAllPermissions) {
      throw new ForbiddenException('Insufficient permissions');
    }

    return true;
  }
}
