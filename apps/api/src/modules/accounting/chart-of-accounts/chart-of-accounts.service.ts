import { BadRequestException, Injectable } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { CreateChartOfAccountDto } from './dto/create-chart-of-account.dto';

@Injectable()
export class ChartOfAccountsService {
  constructor(private readonly prisma: PrismaService) {}

  createAccount(dto: CreateChartOfAccountDto) {
    // TODO: Add account code policy, parent type validation, and audit logging.
    return this.prisma.chartOfAccount.create({
      data: {
        tenantId: dto.tenantId,
        code: dto.code,
        name: dto.name,
        type: dto.type,
        parentId: dto.parentId,
      },
    });
  }

  listAccounts(tenantId: string) {
    if (!tenantId) {
      throw new BadRequestException('tenantId query parameter is required');
    }

    return this.prisma.chartOfAccount.findMany({
      where: { tenantId },
      include: { children: true },
      orderBy: { code: 'asc' },
    });
  }
}
