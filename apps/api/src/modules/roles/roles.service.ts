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

  async createRole(tenantId: string, dto: import('./dto/role.dto').CreateRoleDto) {
    const existing = await this.prisma.role.findUnique({
      where: { tenantId_name: { tenantId, name: dto.name } },
    });
    if (existing) {
      throw new ConflictException('A role with this name already exists in this tenant');
    }

    return this.prisma.$transaction(async (tx) => {
      const role = await tx.role.create({
        data: {
          tenantId,
          name: dto.name,
          description: dto.description,
          isSystemRole: false,
        },
      });

      if (dto.permissionIds && dto.permissionIds.length > 0) {
        await tx.rolePermission.createMany({
          data: dto.permissionIds.map((permId) => ({
            tenantId,
            roleId: role.id,
            permissionId: permId,
          })),
        });
      }

      return role;
    });
  }

  async updateRole(tenantId: string, roleId: string, dto: import('./dto/role.dto').UpdateRoleDto) {
    const role = await this.prisma.role.findUnique({ where: { id: roleId } });
    if (!role || role.tenantId !== tenantId) {
      throw new NotFoundException('Role not found');
    }

    if (role.isSystemRole) {
      throw new ConflictException('Cannot edit a system role');
    }

    if (dto.name && dto.name !== role.name) {
      const existing = await this.prisma.role.findUnique({
        where: { tenantId_name: { tenantId, name: dto.name } },
      });
      if (existing) {
        throw new ConflictException('A role with this name already exists in this tenant');
      }
    }

    return this.prisma.$transaction(async (tx) => {
      const updatedRole = await tx.role.update({
        where: { id: roleId },
        data: {
          name: dto.name ?? role.name,
          description: dto.description ?? role.description,
        },
      });

      if (dto.permissionIds) {
        // Delete all old permissions
        await tx.rolePermission.deleteMany({
          where: { roleId },
        });

        // Create new permissions
        if (dto.permissionIds.length > 0) {
          await tx.rolePermission.createMany({
            data: dto.permissionIds.map((permId) => ({
              tenantId,
              roleId,
              permissionId: permId,
            })),
          });
        }
      }

      return updatedRole;
    });
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
            tenantId,
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
