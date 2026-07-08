import { Injectable, ConflictException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';
import { DEFAULT_ROLES } from '@erp-ai/shared';

@Injectable()
export class RolesService {
  constructor(private readonly prisma: PrismaService) {}

  async findAllByTenant(tenantId: string) {
    return this.prisma.role.findMany({
      where: { tenantId },
      include: {
        rolePermissions: {
          include: {
            permission: {
              select: { key: true, description: true },
            },
          },
        },
      },
      orderBy: { name: 'asc' },
    });
  }

  async findOneByTenant(tenantId: string, roleId: string) {
    const role = await this.prisma.role.findUnique({
      where: { id: roleId },
      include: {
        rolePermissions: {
          include: {
            permission: {
              select: { key: true, description: true },
            },
          },
        },
      },
    });

    if (!role || role.tenantId !== tenantId) {
      throw new NotFoundException('Role not found');
    }

    return role;
  }

  /**
   * Seeds the default system roles (Owner, Admin, Accountant, Viewer) for a new tenant.
   * Requires permissions to be seeded first.
   */
  async seedDefaultRolesForTenant(tenantId: string): Promise<void> {
    const existingCount = await this.prisma.role.count({
      where: { tenantId },
    });

    if (existingCount > 0) {
      throw new ConflictException('Roles have already been seeded for this tenant');
    }

    // Fetch the actual permission records for this tenant so we can link them
    const permissions = await this.prisma.permission.findMany({
      where: { tenantId },
    });

    const permissionMap = new Map(permissions.map((p) => [p.key, p.id]));

    // Transaction to ensure all roles and links are created atomically
    await this.prisma.$transaction(async (tx) => {
      for (const roleData of Object.values(DEFAULT_ROLES)) {
        // Create the role
        const role = await tx.role.create({
          data: {
            tenantId,
            name: roleData.name,
            description: roleData.description,
            isSystemRole: true,
          },
        });

        // Link the permissions
        const rolePermissionsData = roleData.permissions.map((permKey) => {
          const permissionId = permissionMap.get(permKey);
          if (!permissionId) {
             throw new Error(`Critical: Permission ${permKey} not found during role seeding`);
          }
          return {
            roleId: role.id,
            permissionId,
          };
        });

        await tx.rolePermission.createMany({
          data: rolePermissionsData,
        });
      }
    });
  }
}
