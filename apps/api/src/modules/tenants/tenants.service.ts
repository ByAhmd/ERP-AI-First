import { Injectable } from '@nestjs/common';
import { SAUDI_ARABIA } from '@erp-ai/shared';
import { PrismaService } from '../../database/prisma.service';
import { CreateTenantDto } from './dto/create-tenant.dto';

@Injectable()
export class TenantsService {
  constructor(private readonly prisma: PrismaService) {}

  createTenant(dto: CreateTenantDto) {
    // TODO: Add tenant onboarding workflow, owner user creation, and audit logging.
    return this.prisma.tenant.create({
      data: {
        name: dto.name,
        commercialRegNo: dto.commercialRegNo,
        vatRegistrationNo: dto.vatRegistrationNo,
        country: SAUDI_ARABIA.defaultCountry,
        currency: SAUDI_ARABIA.defaultCurrency,
      },
    });
  }

  listTenants() {
    // TODO: Restrict to authorized platform admins before production.
    return this.prisma.tenant.findMany({
      orderBy: { createdAt: 'desc' },
    });
  }
}
