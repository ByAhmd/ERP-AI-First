import { Injectable, Logger } from '@nestjs/common';
import { ALL_PERMISSION_KEYS, DEFAULT_ROLES, PERMISSION_DESCRIPTIONS, SAUDI_ARABIA } from '@erp-ai/shared';
import { PrismaService } from '../../database/prisma.service';
import { CreateTenantDto } from './dto/create-tenant.dto';
import { ChartOfAccountsService } from '../accounting/chart-of-accounts/chart-of-accounts.service';

@Injectable()
export class TenantsService {
  private readonly logger = new Logger(TenantsService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly chartOfAccountsService: ChartOfAccountsService,
  ) {}

  async createTenant(userId: string, dto: CreateTenantDto) {
    const tenant = await this.prisma.$transaction(async (tx) => {
      // 1. Create the tenant
      const tenant = await tx.tenant.create({
        data: {
          name: dto.name,
          commercialRegNo: dto.commercialRegNo,
          vatRegistrationNo: dto.vatRegistrationNo,
          country: SAUDI_ARABIA.defaultCountry,
          currency: SAUDI_ARABIA.defaultCurrency,
        },
      });

      // 2. Seed all permissions for this tenant
      await tx.permission.createMany({
        data: ALL_PERMISSION_KEYS.map((key) => ({
          tenantId: tenant.id,
          key,
          description: PERMISSION_DESCRIPTIONS[key] ?? key,
        })),
        skipDuplicates: true,
      });

      // 3. Create the Owner role (full access)
      const ownerRoleTemplate = DEFAULT_ROLES.OWNER;
      const ownerRole = await tx.role.create({
        data: {
          tenantId: tenant.id,
          name: ownerRoleTemplate.name,
          description: ownerRoleTemplate.description,
          isSystemRole: true,
        },
      });

      // 4. Fetch the seeded permissions for this tenant
      const permissions = await tx.permission.findMany({
        where: { tenantId: tenant.id },
        select: { id: true, key: true },
      });

      // 5. Assign all permissions to the Owner role
      await tx.rolePermission.createMany({
        data: permissions.map((p) => ({
          roleId: ownerRole.id,
          permissionId: p.id,
        })),
        skipDuplicates: true,
      });

      // 6. Also create the Admin and Accountant roles for future use
      for (const roleTemplate of [DEFAULT_ROLES.ADMIN, DEFAULT_ROLES.ACCOUNTANT, DEFAULT_ROLES.VIEWER]) {
        const role = await tx.role.create({
          data: {
            tenantId: tenant.id,
            name: roleTemplate.name,
            description: roleTemplate.description,
            isSystemRole: true,
          },
        });

        const rolePerms = permissions.filter((p) =>
          (roleTemplate.permissions as readonly string[]).includes(p.key),
        );

        if (rolePerms.length > 0) {
          await tx.rolePermission.createMany({
            data: rolePerms.map((p) => ({
              roleId: role.id,
              permissionId: p.id,
            })),
            skipDuplicates: true,
          });
        }
      }

      // 7. Add the creating user as an Active Owner of this tenant
      await tx.tenantUser.create({
        data: {
          userId,
          tenantId: tenant.id,
          roleId: ownerRole.id,
          status: 'Active',
        },
      });

      // 8. Seed the standard SME Chart of Accounts template for the new tenant
      try {
        await this.chartOfAccountsService.seedSmeTemplate(tenant.id, tx);
        this.logger.log(`Successfully seeded SME Chart of Accounts for tenant ${tenant.id}`);
      } catch (error) {
        this.logger.error(`Failed to seed SME Chart of Accounts for tenant ${tenant.id}`, error);
        throw error; // Let the transaction fail if seeding fails
      }

      return tenant;
    });
    return tenant;
  }

  async listTenants(userId: string) {
    const userTenants = await this.prisma.tenantUser.findMany({
      where: { userId },
      include: { tenant: true },
      orderBy: { createdAt: 'desc' },
    });

    return userTenants.map((ut) => ut.tenant);
  }

  async updateTenant(tenantId: string, data: any) {
    return this.prisma.tenant.update({
      where: { id: tenantId },
      data,
    });
  }
}
