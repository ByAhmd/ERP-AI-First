import { Injectable, ConflictException } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';
import { ALL_PERMISSION_KEYS, PERMISSION_DESCRIPTIONS, PermissionKey } from '@erp-ai/shared';

@Injectable()
export class PermissionsService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Retrieves all permissions defined for a tenant.
   */
  async findAllByTenant(tenantId: string) {
    return this.prisma.permission.findMany({
      where: { tenantId },
      orderBy: { key: 'asc' },
    });
  }

  /**
   * Seeds the default platform permissions into a tenant's database namespace.
   * This should be called automatically when a new tenant is created.
   */
  async seedDefaultPermissionsForTenant(tenantId: string): Promise<void> {
    const existingCount = await this.prisma.permission.count({
      where: { tenantId },
    });

    if (existingCount > 0) {
      throw new ConflictException('Permissions have already been seeded for this tenant');
    }

    const data = ALL_PERMISSION_KEYS.map((key) => ({
      tenantId,
      key,
      description: PERMISSION_DESCRIPTIONS[key as PermissionKey],
    }));

    await this.prisma.permission.createMany({
      data,
      skipDuplicates: true,
    });
  }
}
