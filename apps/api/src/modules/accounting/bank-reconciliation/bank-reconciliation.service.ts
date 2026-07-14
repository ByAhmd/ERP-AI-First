import { Injectable, BadRequestException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import Decimal from 'decimal.js';
import { UploadStatementDto } from './dto/bank-reconciliation.dto';

@Injectable()
export class BankReconciliationService {
  constructor(private readonly prisma: PrismaService) {}

  async getReconciliations(tenantId: string) {
    return this.prisma.reconciliation.findMany({
      where: { tenantId },
      include: {
        bankStatement: {
          include: { account: true }
        },
      },
      orderBy: { createdAt: 'desc' },
    });
  }

  async getReconciliation(tenantId: string, id: string) {
    const recon = await this.prisma.reconciliation.findUnique({
      where: { id, tenantId },
      include: {
        bankStatement: {
          include: { 
            transactions: true,
            account: true
          },
        },
        journalLines: true,
      },
    });
    if (!recon) throw new NotFoundException('Reconciliation not found');
    return recon;
  }

  async getUnreconciledLines(tenantId: string, accountId: string) {
    return this.prisma.journalEntryLine.findMany({
      where: {
        tenantId,
        accountId,
        reconciliationId: null,
      },
    });
  }

  async uploadStatement(tenantId: string, dto: UploadStatementDto) {
    const account = await this.prisma.chartOfAccount.findUnique({
      where: { id: dto.accountId, tenantId },
    });
    if (!account) throw new NotFoundException('Account not found');

    return this.prisma.$transaction(async (tx: any) => {
      const statement = await tx.bankStatement.create({
        data: {
          tenantId,
          accountId: dto.accountId,
          statementDate: new Date(dto.statementDate),
          openingBalance: new Decimal(dto.openingBalance).toString(),
          closingBalance: new Decimal(dto.closingBalance).toString(),
        },
      });

      if (dto.transactions && dto.transactions.length > 0) {
        await tx.bankStatementTransaction.createMany({
          data: dto.transactions.map((t: any) => ({
            bankStatementId: statement.id,
            date: new Date(t.date),
            description: t.description,
            amount: new Decimal(t.amount).toString(),
            reference: t.reference,
          })),
        });
      }

      const reconciliation = await tx.reconciliation.create({
        data: {
          tenantId,
          bankStatementId: statement.id,
          accountId: dto.accountId,
          status: 'Draft',
        },
      });

      return {
        statementId: statement.id,
        reconciliationId: reconciliation.id,
        status: reconciliation.status,
      };
    });
  }

  async autoMatch(tenantId: string, reconciliationId: string) {
    const reconciliation = await this.prisma.reconciliation.findUnique({
      where: { id: reconciliationId, tenantId },
      include: {
        bankStatement: {
          include: { transactions: true },
        },
        journalLines: true,
      },
    });

    if (!reconciliation) throw new NotFoundException('Reconciliation not found');
    if (reconciliation.status === 'Reconciled') throw new BadRequestException('Already reconciled');

    const unreconciledLines = await this.prisma.journalEntryLine.findMany({
      where: {
        tenantId,
        accountId: reconciliation.accountId,
        reconciliationId: null,
      },
    });

    let matchedCount = 0;
    const matchedLineIds = new Set<string>();

    await this.prisma.$transaction(async (tx: any) => {
      for (const stxn of reconciliation.bankStatement.transactions) {
        const stmtAmount = new Decimal(stxn.amount.toString());

        const match = unreconciledLines.find((jl: any) => {
          if (matchedLineIds.has(jl.id)) return false;

          const jlAmount = new Decimal(jl.debit.toString()).minus(new Decimal(jl.credit.toString()));
          const isAmountMatch = stmtAmount.equals(jlAmount);

          const daysDiff = Math.abs((stxn.date.getTime() - jl.createdAt.getTime()) / (1000 * 3600 * 24));
          const isDateMatch = daysDiff <= 3; 

          return isAmountMatch && isDateMatch;
        });

        if (match) {
          matchedLineIds.add(match.id);
          matchedCount++;

          await tx.journalEntryLine.update({
            where: { id: match.id },
            data: { reconciliationId: reconciliation.id },
          });
        }
      }
    });

    return { matchedCount };
  }

  async manualMatch(tenantId: string, reconciliationId: string, journalLineIds: string[]) {
    const reconciliation = await this.prisma.reconciliation.findUnique({
      where: { id: reconciliationId, tenantId },
    });

    if (!reconciliation) throw new NotFoundException('Reconciliation not found');
    if (reconciliation.status === 'Reconciled') throw new BadRequestException('Already reconciled');

    const updated = await this.prisma.journalEntryLine.updateMany({
      where: {
        tenantId,
        id: { in: journalLineIds },
        accountId: reconciliation.accountId,
        reconciliationId: null, // Only match if currently unreconciled
      },
      data: {
        reconciliationId,
      },
    });

    return { matchedCount: updated.count };
  }

  async manualUnmatch(tenantId: string, reconciliationId: string, journalLineIds: string[]) {
    const reconciliation = await this.prisma.reconciliation.findUnique({
      where: { id: reconciliationId, tenantId },
    });

    if (!reconciliation) throw new NotFoundException('Reconciliation not found');
    if (reconciliation.status === 'Reconciled') throw new BadRequestException('Already reconciled');

    const updated = await this.prisma.journalEntryLine.updateMany({
      where: {
        tenantId,
        id: { in: journalLineIds },
        accountId: reconciliation.accountId,
        reconciliationId, // Only unmatch if currently matched to this recon
      },
      data: {
        reconciliationId: null,
      },
    });

    return { unmatchedCount: updated.count };
  }

  async completeReconciliation(tenantId: string, reconciliationId: string, userId: string) {
    const reconciliation = await this.prisma.reconciliation.findUnique({
      where: { id: reconciliationId, tenantId },
      include: {
        bankStatement: {
          include: { transactions: true },
        },
        journalLines: true,
      },
    });

    if (!reconciliation) throw new NotFoundException('Reconciliation not found');
    if (reconciliation.status === 'Reconciled') {
      throw new BadRequestException('This reconciliation is already complete.');
    }

    const openingBalance = new Decimal(reconciliation.bankStatement.openingBalance.toString());
    const closingBalance = new Decimal(reconciliation.bankStatement.closingBalance.toString());

    const matchedLineAmounts = reconciliation.journalLines.reduce((sum: Decimal, jl: any) => {
      const net = new Decimal(jl.debit.toString()).minus(new Decimal(jl.credit.toString()));
      return sum.plus(net);
    }, new Decimal(0));

    const calculatedClosing = openingBalance.plus(matchedLineAmounts);

    if (!calculatedClosing.equals(closingBalance)) {
      throw new BadRequestException(
        `Reconciliation balance mismatch. ` +
        `Expected closing balance: ${closingBalance.toString()}, ` +
        `Calculated from matched entries: ${calculatedClosing.toString()}. ` +
        `Please match all transactions before completing.`
      );
    }

    return this.prisma.reconciliation.update({
      where: { id: reconciliationId },
      data: {
        status: 'Reconciled',
        reconciledAt: new Date(),
        reconciledById: userId,
      },
    });
  }
}
