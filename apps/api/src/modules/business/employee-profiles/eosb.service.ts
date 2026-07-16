import { Injectable, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { JournalEntriesService } from '../../accounting/journal-entries/journal-entries.service';
import { Decimal } from 'decimal.js';

@Injectable()
export class EosbService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly journalEntriesService: JournalEntriesService
  ) {}

  private computeEosb(wage: Decimal, hireDate: Date, evaluationDate: Date): Decimal {
    const years = Math.max(0, (evaluationDate.getTime() - hireDate.getTime()) / (1000 * 60 * 60 * 24 * 365.25));
    if (years <= 5) {
      return wage.div(2).mul(years);
    } else {
      const firstFive = wage.div(2).mul(5);
      const rest = wage.mul(years - 5);
      return firstFive.plus(rest);
    }
  }

  /**
   * Under Saudi Labor Law, employees receive a severance payout.
   * First 5 years: Half-month salary per year.
   * 6+ years: One full month salary per year.
   */
  async calculateCurrentLiability(tenantId: string, employeeProfileId: string, departureReason: 'Resignation' | 'Termination' | 'Active' = 'Active') {
    const profile = await this.prisma.employeeProfile.findUnique({
      where: { id: employeeProfileId, tenantId },
    });

    if (!profile) throw new BadRequestException('Employee Profile not found');

    const now = new Date();
    const hireDate = profile.hireDate || now;
    const yearsOfService = (now.getTime() - hireDate.getTime()) / (1000 * 60 * 60 * 24 * 365.25);
    
    // EOSB uses the *last* basic wage + fixed allowances. We will use basicSalary as the base.
    const monthlyWage = new Decimal(profile.basicSalary).plus(profile.housingAllowance).plus(profile.transportAllowance);
    
    let eosbTotal = this.computeEosb(monthlyWage, hireDate, now);

    // Apply Resignation rules which pro-rate the payout
    if (departureReason === 'Resignation') {
      if (yearsOfService < 2) {
        eosbTotal = new Decimal(0); // No payout
      } else if (yearsOfService >= 2 && yearsOfService < 5) {
        eosbTotal = eosbTotal.mul(1 / 3); // One-third
      } else if (yearsOfService >= 5 && yearsOfService < 10) {
        eosbTotal = eosbTotal.mul(2 / 3); // Two-thirds
      }
      // >= 10 years gets full amount even on resignation
    }

    return {
      yearsOfService: yearsOfService.toFixed(2),
      monthlyWageBase: monthlyWage.toString(),
      accruedLiability: eosbTotal.toFixed(2)
    };
  }

  /**
   * Cron job or manual trigger to book the monthly accrued EOSB liability to the GL.
   */
  async bookMonthlyAccrual(tenantId: string, periodName: string) {
    const profiles = await this.prisma.employeeProfile.findMany({ where: { tenantId } });
    
    let totalAccrualForMonth = new Decimal(0);
    
    for (const profile of profiles) {
       // Correct approach: Calculate liability today vs liability 1 month ago using the *current* wage.
       // This automatically catches up any underfunded liability due to recent salary increases.
       const now = new Date();
       const oneMonthAgo = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate());
       const hireDate = profile.hireDate || now;
       
       const monthlyWage = new Decimal(profile.basicSalary).plus(profile.housingAllowance).plus(profile.transportAllowance);
       
       const currentLiability = this.computeEosb(monthlyWage, hireDate, now);
       const pastLiability = this.computeEosb(monthlyWage, hireDate, oneMonthAgo);
       
       let monthlyAccrual = currentLiability.minus(pastLiability);
       if (monthlyAccrual.isNegative()) monthlyAccrual = new Decimal(0);
       
       totalAccrualForMonth = totalAccrualForMonth.plus(monthlyAccrual);
    }

    // Debit: EOSB Expense
    // Credit: Provision for EOSB Liability
    const expenseParent = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '5100' } } });
    let eosbExpenseAccount = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '5130' } } });
    if (!eosbExpenseAccount) {
      eosbExpenseAccount = await this.prisma.chartOfAccount.create({ 
        data: { tenantId, code: '5130', name: 'EOSB Expense', type: 'Expense', parentId: expenseParent?.id } 
      });
    }

    const liabilityParent = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '2100' } } });
    let eosbLiabilityAccount = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '2160' } } });
    if (!eosbLiabilityAccount) {
      eosbLiabilityAccount = await this.prisma.chartOfAccount.create({ 
        data: { tenantId, code: '2160', name: 'Provision for EOSB', type: 'Liability', parentId: liabilityParent?.id } 
      });
    }

    return this.prisma.$transaction(async (tx) => {
      const je = await this.journalEntriesService.create(tenantId, {
        entryDate: new Date(),
        description: `Monthly EOSB Accrual: ${periodName}`,
        lines: [
          {
            accountId: eosbExpenseAccount.id,
            description: `EOSB Expense for ${periodName}`,
            debit: totalAccrualForMonth.toString(),
            credit: '0',
          },
          {
            accountId: eosbLiabilityAccount.id,
            description: `Provision for EOSB for ${periodName}`,
            debit: '0',
            credit: totalAccrualForMonth.toString(),
          }
        ]
      }, tx);
      
      return je;
    });
  }
}
