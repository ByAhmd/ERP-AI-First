import { Injectable } from '@nestjs/common';
import { GeneralLedgerService } from '../accounting/general-ledger/general-ledger.service';
import { PrismaService } from '../../database/prisma.service';
import Decimal from 'decimal.js';

@Injectable()
export class ReportsService {
  constructor(
    private readonly glService: GeneralLedgerService,
    private readonly prisma: PrismaService,
  ) {}

  async getPnl(tenantId: string, startDate?: Date, endDate?: Date) {
    const tb = await this.glService.getTrialBalance(tenantId, startDate, endDate);
    
    const revenueAccounts = tb.items.filter(a => a.type === 'Revenue');
    const expenseAccounts = tb.items.filter(a => a.type === 'Expense');

    const totalRevenue = revenueAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const totalExpense = expenseAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const netIncome = totalRevenue.minus(totalExpense);

    return {
      revenue: revenueAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      expenses: expenseAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      totalRevenue: totalRevenue.toString(),
      totalExpense: totalExpense.toString(),
      netIncome: netIncome.toString(),
    };
  }

  async getBalanceSheet(tenantId: string, asOfDate?: Date) {
    // Balance sheet is cumulative from the beginning of time up to asOfDate
    const tb = await this.glService.getTrialBalance(tenantId, undefined, asOfDate);

    const assetAccounts = tb.items.filter(a => a.type === 'Asset');
    const liabilityAccounts = tb.items.filter(a => a.type === 'Liability');
    const equityAccounts = tb.items.filter(a => a.type === 'Equity');
    const revenueAccounts = tb.items.filter(a => a.type === 'Revenue');
    const expenseAccounts = tb.items.filter(a => a.type === 'Expense');

    const totalAssets = assetAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const totalLiabilities = liabilityAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    
    const totalRevenue = revenueAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const totalExpense = expenseAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const netIncome = totalRevenue.minus(totalExpense);

    const baseEquity = equityAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));
    const totalEquity = baseEquity.plus(netIncome);

    return {
      assets: assetAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      liabilities: liabilityAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      equity: equityAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      totalAssets: totalAssets.toString(),
      totalLiabilities: totalLiabilities.toString(),
      totalEquity: totalEquity.toString(),
      netIncome: netIncome.toString(),
    };
  }

  async getCashFlow(tenantId: string, startDate?: Date, endDate?: Date) {
    // A simplistic direct cash flow estimation based on cash account movements
    const tb = await this.glService.getTrialBalance(tenantId, startDate, endDate);
    const cashAccounts = tb.items.filter(a => a.type === 'Asset' && (a.name.toLowerCase().includes('cash') || a.name.toLowerCase().includes('bank')));

    const netCashFlow = cashAccounts.reduce((sum, acc) => sum.plus(acc.balance), new Decimal(0));

    return {
      cashAccounts: cashAccounts.map(a => ({ id: a.id, code: a.code, name: a.name, balance: a.balance })),
      netCashFlow: netCashFlow.toString(),
    };
  }

  async getDashboardKpis(tenantId: string) {
    // Current month start
    const now = new Date();
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);

    const isCurrentMonth = await this.getPnl(tenantId, startOfMonth, now);
    const plAllTime = await this.getPnl(tenantId);
    
    // Get Open Receivables (Unpaid Sales Invoices)
    const unpaidSales = await this.prisma.invoice.aggregate({
      where: {
        tenantId,
        type: 'SalesInvoice',
        status: { in: ['Approved', 'PendingApproval'] }
      },
      _sum: {
        total: true,
        amountPaid: true,
      }
    });

    const openReceivables = Number(unpaidSales._sum.total || 0) - Number(unpaidSales._sum.amountPaid || 0);

    // Get Open Payables (Unpaid Purchase Invoices)
    const unpaidPurchases = await this.prisma.invoice.aggregate({
      where: {
        tenantId,
        type: 'PurchaseInvoice',
        status: { in: ['Approved', 'PendingApproval'] }
      },
      _sum: {
        total: true,
        amountPaid: true,
      }
    });

    const openPayables = Number(unpaidPurchases._sum.total || 0) - Number(unpaidPurchases._sum.amountPaid || 0);

    // Get HR Headcount
    const headcount = await this.prisma.employeeProfile.count({
      where: { tenantId }
    });

    // Get CRM Pipeline (Open Opportunities)
    const pipeline = await this.prisma.opportunity.aggregate({
      where: {
        tenantId,
        stage: { notIn: ['Won', 'Lost'] }
      },
      _sum: {
        value: true
      }
    });
    const crmPipelineValue = Number(pipeline._sum.value || 0);

    // Get Total Inventory Valuation
    const inventoryItems = await this.prisma.item.findMany({
      where: { tenantId, type: 'Product' },
      include: { inventoryBalances: true }
    });
    let inventoryValuation = 0;
    inventoryItems.forEach(item => {
      const wac = Number(item.weightedAverageCost || 0);
      const totalQty = item.inventoryBalances.reduce((sum, bal) => sum + Number(bal.quantity || 0), 0);
      inventoryValuation += (wac * totalQty);
    });

    // Mock trend data for chart (6 months)
    const trendData = [];
    for (let i = 5; i >= 0; i--) {
      const monthStart = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const monthEnd = new Date(now.getFullYear(), now.getMonth() - i + 1, 0);
      const monthlyPl = await this.getPnl(tenantId, monthStart, monthEnd);
      trendData.push({
        name: monthStart.toLocaleString('default', { month: 'short' }),
        revenue: Number(monthlyPl.totalRevenue),
        expenses: Number(monthlyPl.totalExpense),
        profit: Number(monthlyPl.netIncome)
      });
    }

    return {
      metrics: {
        monthlyRevenue: Number(isCurrentMonth.totalRevenue),
        monthlyExpenses: Number(isCurrentMonth.totalExpense),
        monthlyProfit: Number(isCurrentMonth.netIncome),
        allTimeRevenue: Number(plAllTime.totalRevenue),
        allTimeProfit: Number(plAllTime.netIncome),
        openReceivables,
        openPayables,
        headcount,
        crmPipelineValue,
        inventoryValuation,
      },
      trendData
    };
  }
}
