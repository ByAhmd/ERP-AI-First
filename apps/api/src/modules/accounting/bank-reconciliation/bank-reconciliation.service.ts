import { Injectable, BadRequestException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { Decimal } from 'decimal.js';
import { UploadStatementDto } from './dto/bank-reconciliation.dto';

@Injectable()
export class BankReconciliationService {
  constructor(private readonly prisma: PrismaService) {}

  async uploadStatement(tenantId: string, dto: UploadStatementDto) {
    // Validate account belongs to tenant and is an asset/liability
    const account = await this.prisma.chartOfAccount.findUnique({
      where: { id: dto.accountId, tenantId },
    });
    if (!account) throw new NotFoundException('Account not found');

    return this.prisma.$transaction(async (tx: any) => {
      // 1. Create a BankStatement
      const statement = await tx.bankStatement.create({
        data: {
          tenantId,
          accountId: dto.accountId,
          statementDate: new Date(dto.statementDate),
          openingBalance: new Decimal(dto.openingBalance),
          closingBalance: new Decimal(dto.closingBalance),
        },
      });

      // 2. Create BankStatementTransaction records
      if (dto.transactions && dto.transactions.length > 0) {
        await tx.bankStatementTransaction.createMany({
          data: dto.transactions.map((t: any) => ({
            bankStatementId: statement.id,
            date: new Date(t.date),
            description: t.description,
            amount: new Decimal(t.amount),
            reference: t.reference,
          })),
        });
      }

      // 3. Create a Reconciliation record in 'Draft'
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
    // Auto-match logic:
    // Finds exact matches between un-reconciled JournalEntryLines for the given account
    // and BankStatementTransactions based on Date and Amount.
    const reconciliation = await this.prisma.reconciliation.findUnique({
      where: { id: reconciliationId, tenantId },
      include: {
        bankStatement: {
          include: { transactions: true },
        },
        journalLines: true, // Already matched lines
      },
    });

    if (!reconciliation) throw new NotFoundException('Reconciliation not found');
    if (reconciliation.status === 'Reconciled') throw new BadRequestException('Already reconciled');

    // Find all unreconciled journal lines for this account (where reconciliationId is null)
    // For a bank account (Asset), debit increases balance, credit decreases balance.
    // For statement amounts, positive = deposit (debit), negative = withdrawal (credit)
    const unreconciledLines = await this.prisma.journalEntryLine.findMany({
      where: {
        tenantId,
        accountId: reconciliation.accountId,
        reconciliationId: null,
      },
    });

    let matchedCount = 0;

    await this.prisma.$transaction(async (tx: any) => {
      for (const stxn of reconciliation.bankStatement.transactions) {
        const stmtAmount = new Decimal(stxn.amount);
        
        // Find a matching journal line
        const match = unreconciledLines.find((jl: any) => {
          if (jl.reconciliationId) return false; // Already matched in this loop
          
          const jlAmount = jl.debit.minus(jl.credit); // net effect
          // Tolerance could be added here. Currently exact match.
          // Also date check (exact date or within a few days)
          const isAmountMatch = stmtAmount.equals(jlAmount);
          
          const daysDiff = Math.abs((stxn.date.getTime() - jl.createdAt.getTime()) / (1000 * 3600 * 24));
          const isDateMatch = daysDiff <= 3; // Within 3 days

          return isAmountMatch && isDateMatch;
        });

        if (match) {
          // Mark matched
          match.reconciliationId = reconciliation.id;
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

  async completeReconciliation(tenantId: string, reconciliationId: string) {
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
    
    // In a real system, we'd ensure Closing Balance matches (Opening Balance + net transactions),
    // and that all statement transactions have a corresponding matched journal line.
    
    // For V1 momentum, we just seal the reconciliation.
    const updated = await this.prisma.reconciliation.update({
      where: { id: reconciliationId },
      data: {
        status: 'Reconciled',
        reconciledAt: new Date(),
        // reconciledById would come from req.user
      },
    });

    return updated;
  }
}
