import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

@Injectable()
export class AccountingPeriodsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAllByTenant(tenantId: string, fiscalYearId?: string) {
    const whereClause: any = { tenantId };
    if (fiscalYearId) {
       whereClause.fiscalYearId = fiscalYearId;
    }
    
    return this.prisma.accountingPeriod.findMany({
      where: whereClause,
      orderBy: { startDate: 'asc' },
    });
  }

  async findActivePeriodByDate(tenantId: string, date: Date) {
    const period = await this.prisma.accountingPeriod.findFirst({
      where: {
        tenantId,
        startDate: { lte: date },
        endDate: { gte: date },
      },
    });

    if (!period) {
      throw new BadRequestException('No accounting period found for the given date');
    }

    if (period.status === 'Closed') {
      throw new BadRequestException('The accounting period for this date is closed');
    }

    return period;
  }

  async updateStatus(tenantId: string, id: string, status: 'Open' | 'Closed' | 'Adjusting') {
    const period = await this.prisma.accountingPeriod.findUnique({
      where: { id },
    });

    if (!period || period.tenantId !== tenantId) {
      throw new NotFoundException('Accounting period not found');
    }

    if (period.status === 'Closed' && status !== 'Closed') {
      // Reopening a closed period is a highly sensitive operation, typically requires special permissions
      // We will allow it here but AuditInterceptor will catch it
    }

    return this.prisma.accountingPeriod.update({
      where: { id },
      data: { status },
    });
  }
}
