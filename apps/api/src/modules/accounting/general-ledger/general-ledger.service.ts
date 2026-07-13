import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import Decimal from 'decimal.js';

@Injectable()
export class GeneralLedgerService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Generates a Trial Balance for a given date range.
   * Aggregates Debits and Credits per active account.
   */
  async getTrialBalance(tenantId: string, startDate?: Date, endDate?: Date) {
    const dateFilter: any = {};
    if (startDate) dateFilter.gte = startDate;
    if (endDate) dateFilter.lte = endDate;

    // 1. Fetch all active accounts
    const accounts = await this.prisma.chartOfAccount.findMany({
      where: { tenantId, status: 'Active' },
      select: { id: true, code: true, name: true, type: true }
    });

    // 2. Fetch aggregated journal lines for posted entries
    const lines = await this.prisma.journalEntryLine.groupBy({
      by: ['accountId'],
      where: {
        tenantId,
        journalEntry: {
          status: 'Posted',
          ...(Object.keys(dateFilter).length > 0 ? { entryDate: dateFilter } : {})
        }
      },
      _sum: {
        debit: true,
        credit: true
      }
    });

    // 3. Map aggregations to accounts
    const linesMap = new Map(lines.map(l => [l.accountId, l._sum]));

    const trialBalance = accounts.map(acc => {
      const sums = linesMap.get(acc.id) || { debit: new Decimal(0), credit: new Decimal(0) };
      
      const debit = new Decimal(sums.debit || 0);
      const credit = new Decimal(sums.credit || 0);
      
      let balance = new Decimal(0);
      let balanceType = 'Debit';
      
      // Standard balance calculation based on account type
      if (acc.type === 'Asset' || acc.type === 'Expense') {
         balance = debit.minus(credit);
         balanceType = balance.isNegative() ? 'Credit' : 'Debit';
      } else {
         balance = credit.minus(debit);
         balanceType = balance.isNegative() ? 'Debit' : 'Credit';
      }

      return {
        id: acc.id,
        code: acc.code,
        name: acc.name,
        type: acc.type,
        totalDebit: debit.toString(),
        totalCredit: credit.toString(),
        balance: balance.abs().toString(),
        balanceType,
      };
    });

    // 4. Calculate grand totals based on net balances
    const grandTotals = trialBalance.reduce((acc, row) => {
       const bal = new Decimal(row.balance);
       if (row.balanceType === 'Debit') {
         return {
           debit: acc.debit.plus(bal),
           credit: acc.credit
         };
       } else {
         return {
           debit: acc.debit,
           credit: acc.credit.plus(bal)
         };
       }
    }, { debit: new Decimal(0), credit: new Decimal(0) });

    return {
      items: trialBalance.filter(r => !new Decimal(r.balance).isZero()), // Filter out zero balance accounts by default
      totals: {
        debit: grandTotals.debit.toString(),
        credit: grandTotals.credit.toString()
      },
      isBalanced: grandTotals.debit.equals(grandTotals.credit)
    };
  }

  /**
   * Retrieves all journal entry lines (transactions) for the general ledger.
   */
  async getTransactions(tenantId: string) {
    return this.prisma.journalEntryLine.findMany({
      where: {
        tenantId,
        journalEntry: {
          status: 'Posted',
        },
      },
      include: {
        account: true,
        journalEntry: true,
      },
      orderBy: {
        journalEntry: {
          entryDate: 'desc',
        },
      },
    });
  }
}
