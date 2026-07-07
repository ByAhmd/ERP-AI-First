import { Injectable, ConflictException, BadRequestException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

export interface CreateFiscalYearDto {
  name: string; // e.g. "FY 2026"
  startDate: Date;
  endDate: Date;
}

@Injectable()
export class FiscalYearsService {
  constructor(private readonly prisma: PrismaService) {}

  async create(tenantId: string, dto: CreateFiscalYearDto) {
    if (dto.startDate >= dto.endDate) {
      throw new BadRequestException('Start date must be before end date');
    }

    const existing = await this.prisma.fiscalYear.findUnique({
      where: { tenantId_name: { tenantId, name: dto.name } }
    });

    if (existing) {
      throw new ConflictException(`Fiscal year with name ${dto.name} already exists`);
    }

    // Wrap creation in transaction to generate periods atomically
    return this.prisma.$transaction(async (tx) => {
      const fiscalYear = await tx.fiscalYear.create({
        data: {
          tenantId,
          name: dto.name,
          startDate: dto.startDate,
          endDate: dto.endDate,
          status: 'Open',
        }
      });

      // Generate 12 monthly periods
      const periodsToCreate = [];
      let currentStart = new Date(dto.startDate);

      for (let i = 1; i <= 12; i++) {
        const nextMonth = new Date(currentStart);
        nextMonth.setUTCMonth(currentStart.getUTCMonth() + 1);
        
        // Ensure the end date of the month is correct
        const currentEnd = new Date(nextMonth);
        currentEnd.setUTCMilliseconds(currentEnd.getUTCMilliseconds() - 1);

        // Name format: "Jan 2026", "Feb 2026" etc. (Using English abbreviations as default)
        const monthName = currentStart.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' });
        const year = currentStart.getUTCFullYear();
        
        periodsToCreate.push({
          tenantId,
          fiscalYearId: fiscalYear.id,
          name: `${monthName} ${year}`,
          startDate: new Date(currentStart),
          endDate: new Date(currentEnd),
          status: 'Open' as any,
        });

        currentStart = nextMonth;
        
        // If we exceeded the fiscal year end date early (unusual but possible if FY is < 12 months)
        if (currentStart > dto.endDate) {
           break;
        }
      }

      if (periodsToCreate.length > 0) {
         // Fix the last period's end date to match the fiscal year's exact end date
         periodsToCreate[periodsToCreate.length - 1].endDate = new Date(dto.endDate);
         
         await tx.accountingPeriod.createMany({
            data: periodsToCreate
         });
      }

      return fiscalYear;
    });
  }

  async findAll(tenantId: string) {
    return this.prisma.fiscalYear.findMany({
      where: { tenantId },
      include: {
         accountingPeriods: {
            orderBy: { startDate: 'asc' }
         }
      },
      orderBy: { startDate: 'desc' }
    });
  }

  async close(tenantId: string, id: string) {
    const fy = await this.prisma.fiscalYear.findUnique({
      where: { id }
    });

    if (!fy || fy.tenantId !== tenantId) {
      throw new NotFoundException('Fiscal year not found');
    }

    if (fy.status === 'Closed') {
      throw new BadRequestException('Fiscal year is already closed');
    }

    // Ensure all periods are closed before closing FY
    const openPeriods = await this.prisma.accountingPeriod.count({
      where: { fiscalYearId: id, status: { in: ['Open', 'Adjusting'] } }
    });

    if (openPeriods > 0) {
      throw new BadRequestException('Cannot close fiscal year with open or adjusting periods');
    }

    return this.prisma.fiscalYear.update({
      where: { id },
      data: { status: 'Closed' }
    });
  }
}
