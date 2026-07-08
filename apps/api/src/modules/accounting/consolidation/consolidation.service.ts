import { Injectable, BadRequestException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { Decimal } from 'decimal.js';
import { SetPeriodExchangeRateDto } from './dto/consolidation.dto';

@Injectable()
export class ConsolidationService {
  constructor(private readonly prisma: PrismaService) {}

  async setPeriodExchangeRate(tenantId: string, dto: SetPeriodExchangeRateDto) {
    return this.prisma.periodExchangeRate.create({
      data: {
        tenantId,
        currencyId: dto.currencyId,
        accountingPeriodId: dto.accountingPeriodId,
        rate: new Decimal(dto.rate),
      },
    });
  }

  async getConsolidatedTrialBalance(parentTenantId: string, startDate: string, endDate: string) {
    const parent = await this.prisma.tenant.findUnique({
      where: { id: parentTenantId },
      include: {
        tenantChildren: { include: { childTenant: true } },
      },
    });

    if (!parent) throw new NotFoundException('Parent tenant not found');

    const sDate = new Date(startDate);
    const eDate = new Date(endDate);

    // Get parent's own trial balance
    const parentTb = await this.getTenantTrialBalance(parent.id, sDate, eDate);
    
    const consolidatedTb: Record<string, { accountName: string, debit: Decimal, credit: Decimal }> = {};

    // Map parent balances
    parentTb.forEach(line => {
      consolidatedTb[line.code] = {
        accountName: line.name,
        debit: new Decimal(line.debit),
        credit: new Decimal(line.credit),
      };
    });

    // Translate and aggregate children
    for (const childRel of parent.tenantChildren) {
      const child = childRel.childTenant;
      const childTb = await this.getTenantTrialBalance(child.id, sDate, eDate);

      let exRate = new Decimal(1);

      if (child.currency !== parent.currency) {
        // Find currency id for child
        const currencyObj = await this.prisma.currency.findUnique({
          where: { tenantId_code: { tenantId: parent.id, code: child.currency } },
        });

        if (!currencyObj) {
           throw new BadRequestException(`Parent tenant does not have currency ${child.currency} configured`);
        }

        // Fetch period exchange rate matching the period date
        // Since we only have accountingPeriodId in schema, let's find the period by date
        const period = await this.prisma.accountingPeriod.findFirst({
           where: {
             tenantId: parent.id,
             startDate: { lte: sDate },
             endDate: { gte: eDate },
           }
        });

        if (period) {
          const periodRate = await this.prisma.periodExchangeRate.findFirst({
            where: {
              tenantId: parent.id,
              currencyId: currencyObj.id,
              accountingPeriodId: period.id,
            },
          });
          if (periodRate) {
             exRate = new Decimal(periodRate.rate);
          }
        }
      }

      for (const line of childTb) {
        // Simplified V1 translation using the single period rate
        const rate = exRate;

        const translatedDebit = new Decimal(line.debit).mul(rate);
        const translatedCredit = new Decimal(line.credit).mul(rate);

        if (!consolidatedTb[line.code]) {
          consolidatedTb[line.code] = {
            accountName: line.name,
            debit: new Decimal(0),
            credit: new Decimal(0),
          };
        }

        consolidatedTb[line.code].debit = consolidatedTb[line.code].debit.plus(translatedDebit);
        consolidatedTb[line.code].credit = consolidatedTb[line.code].credit.plus(translatedCredit);
      }
    }

    // Convert map to array and format
    return Object.keys(consolidatedTb).map(code => ({
      code,
      name: consolidatedTb[code].accountName,
      debit: consolidatedTb[code].debit.toDecimalPlaces(2).toString(),
      credit: consolidatedTb[code].credit.toDecimalPlaces(2).toString(),
    }));
  }

  private async getTenantTrialBalance(tenantId: string, startDate: Date, endDate: Date) {
    const lines = await this.prisma.journalEntryLine.findMany({
      where: {
        tenantId,
        journalEntry: {
          status: 'Posted',
          entryDate: { gte: startDate, lte: endDate },
        },
      },
      include: {
        account: true,
      },
    });

    const tbMap: Record<string, any> = {};

    for (const line of lines) {
      const code = line.account.code;
      if (!tbMap[code]) {
        tbMap[code] = {
          code,
          name: line.account.name,
          type: line.account.type,
          debit: new Decimal(0),
          credit: new Decimal(0),
        };
      }
      tbMap[code].debit = tbMap[code].debit.plus(new Decimal(line.debit));
      tbMap[code].credit = tbMap[code].credit.plus(new Decimal(line.credit));
    }

    return Object.values(tbMap);
  }
}
