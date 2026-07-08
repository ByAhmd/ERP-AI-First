import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { EstimateZakatDto } from './dto/estimate-zakat.dto';
import Decimal from 'decimal.js';

@Injectable()
export class ZakatService {
  constructor(private readonly prisma: PrismaService) {}

  async estimateZakat(tenantId: string, dto: EstimateZakatDto) {
    const { startDate, endDate } = dto;

    // Fetch Zakat settings
    let setting = await this.prisma.zakatSetting.findUnique({
      where: { tenantId },
    });

    if (!setting) {
      setting = await this.prisma.zakatSetting.create({
        data: { tenantId, isHijriYear: true, zakatBaseMultiplier: 2.5 },
      });
    }

    // 1. Calculate Net Income (Revenue - Expenses)
    const revenueLines = await this.prisma.journalEntryLine.aggregate({
      _sum: { credit: true, debit: true },
      where: {
        tenantId,
        account: { type: 'Revenue' },
        journalEntry: { status: 'Posted', entryDate: { gte: startDate, lte: endDate } },
      },
    });

    const expenseLines = await this.prisma.journalEntryLine.aggregate({
      _sum: { credit: true, debit: true },
      where: {
        tenantId,
        account: { type: 'Expense' },
        journalEntry: { status: 'Posted', entryDate: { gte: startDate, lte: endDate } },
      },
    });

    // Revenue is credit normal
    const revenue = new Decimal(revenueLines._sum.credit || 0).minus(revenueLines._sum.debit || 0);
    // Expense is debit normal
    const expense = new Decimal(expenseLines._sum.debit || 0).minus(expenseLines._sum.credit || 0);

    const netIncome = revenue.minus(expense);

    // 2. Calculate Equity and Long Term Liabilities (for simplicity, all Equity and Liabilities in this estimation)
    const equityLines = await this.prisma.journalEntryLine.aggregate({
      _sum: { credit: true, debit: true },
      where: {
        tenantId,
        account: { type: 'Equity' },
        journalEntry: { status: 'Posted', entryDate: { lte: endDate } },
      },
    });

    const liabilityLines = await this.prisma.journalEntryLine.aggregate({
      _sum: { credit: true, debit: true },
      where: {
        tenantId,
        account: { type: 'Liability' },
        journalEntry: { status: 'Posted', entryDate: { lte: endDate } },
      },
    });

    // Equity and Liability are credit normal
    const equity = new Decimal(equityLines._sum.credit || 0).minus(equityLines._sum.debit || 0);
    const liability = new Decimal(liabilityLines._sum.credit || 0).minus(liabilityLines._sum.debit || 0);

    // 3. Zakat Base = Equity + Long Term Liabilities + Net Income (Simplified)
    // We assume all liability is included here for the sake of the base estimation.
    let zakatBase = equity.plus(liability).plus(netIncome);
    if (zakatBase.isNegative()) {
      zakatBase = new Decimal(0);
    }

    // 4. Calculate Provision
    const multiplier = new Decimal(setting.zakatBaseMultiplier).div(100);
    const estimatedProvision = zakatBase.mul(multiplier);

    return {
      period: { startDate, endDate },
      zakatBase: zakatBase.toNumber(),
      netIncome: netIncome.toNumber(),
      equity: equity.toNumber(),
      liabilities: liability.toNumber(),
      multiplier: setting.zakatBaseMultiplier.toNumber(),
      estimatedProvision: estimatedProvision.toNumber(),
    };
  }
}
