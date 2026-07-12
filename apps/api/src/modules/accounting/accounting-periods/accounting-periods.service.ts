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

  async initializeFiscalYear(tenantId: string, year: number) {
    return this.prisma.$transaction(async (tx) => {
      // Create Fiscal Year
      const startDate = new Date(`${year}-01-01T00:00:00Z`);
      const endDate = new Date(`${year}-12-31T23:59:59Z`);

      const fiscalYear = await tx.fiscalYear.create({
        data: {
          tenantId,
          name: `FY ${year}`,
          startDate,
          endDate,
          status: 'Open',
        }
      });

      // Create 12 Accounting Periods
      for (let month = 1; month <= 12; month++) {
        const pStart = new Date(`${year}-${month.toString().padStart(2, '0')}-01T00:00:00Z`);
        const pEnd = new Date(year, month, 0, 23, 59, 59, 999);
        
        await tx.accountingPeriod.create({
          data: {
            tenantId,
            fiscalYearId: fiscalYear.id,
            name: `${year}-${month.toString().padStart(2, '0')}`,
            startDate: pStart,
            endDate: pEnd,
            status: 'Open', // Usually they are created as 'Open' or 'Future', we'll default to Open for setup
          }
        });
      }

      return fiscalYear;
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
