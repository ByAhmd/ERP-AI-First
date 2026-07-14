import { Injectable, BadRequestException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { ChartOfAccountsService } from '../chart-of-accounts/chart-of-accounts.service';
import { AccountingPeriodsService } from '../accounting-periods/accounting-periods.service';
import Decimal from 'decimal.js';

export interface CreateJournalEntryLineDto {
  accountId: string;
  description?: string;
  debit?: number | string;
  credit?: number | string;
  foreignDebit?: number | string;
  foreignCredit?: number | string;
  currencyId?: string;
  exchangeRate?: number | string;
  contactId?: string;
}

export interface CreateJournalEntryDto {
  entryDate: Date;
  description?: string;
  lines: CreateJournalEntryLineDto[];
}

@Injectable()
export class JournalEntriesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly coaService: ChartOfAccountsService,
    private readonly periodsService: AccountingPeriodsService,
  ) {}

  async create(tenantId: string, dto: CreateJournalEntryDto, tx?: any) {
    if (!dto.lines || dto.lines.length < 2) {
      throw new BadRequestException('A journal entry must have at least two lines.');
    }

    // 1. Validate Accounting Period (Must be Open or Adjusting)
    await this.periodsService.findActivePeriodByDate(tenantId, new Date(dto.entryDate));

    // 2. Validate Zero Balance using decimal.js
    let totalDebits = new Decimal(0);
    let totalCredits = new Decimal(0);

    for (const line of dto.lines) {
      const debit = new Decimal(line.debit || 0);
      const credit = new Decimal(line.credit || 0);

      if (debit.isNegative() || credit.isNegative()) {
        throw new BadRequestException('Debit and Credit amounts cannot be negative.');
      }
      if (!debit.isZero() && !credit.isZero()) {
        throw new BadRequestException('A single line cannot have both a debit and a credit amount.');
      }

      totalDebits = totalDebits.plus(debit);
      totalCredits = totalCredits.plus(credit);

      // Validate account allows posting (not a summary account)
      await this.coaService.validateAccountForPosting(tenantId, line.accountId);
    }

    if (!totalDebits.equals(totalCredits)) {
      throw new BadRequestException(`Journal entry is out of balance. Debits: ${totalDebits.toString()}, Credits: ${totalCredits.toString()}`);
    }

    // BUG-001 FIX: The entry number count MUST happen inside the transaction to be atomic.
    // Using the provided tx client if available, otherwise falling back to prisma.
    const executeCreate = async (client: any) => {
      const year = new Date(dto.entryDate).getUTCFullYear();
      // BUG-029 FIX: Use `lt: new Date(year+1, 0, 1)` to properly capture all entries on Dec 31
      const count = await client.journalEntry.count({
        where: {
          tenantId,
          entryDate: {
            gte: new Date(`${year}-01-01T00:00:00.000Z`),
            lt: new Date(`${year + 1}-01-01T00:00:00.000Z`),
          },
        },
      });
      const entryNumber = `JE-${year}-${String(count + 1).padStart(4, '0')}`;

      return client.journalEntry.create({
        data: {
          tenantId,
          entryNumber,
          entryDate: dto.entryDate,
          description: dto.description,
          status: 'Posted', // Post immediately in this phase
          lines: {
            create: dto.lines.map((line: any) => ({
              tenantId,
              accountId: line.accountId,
              description: line.description,
              debit: new Decimal(line.debit || 0).toString(),
              credit: new Decimal(line.credit || 0).toString(),
              currencyId: line.currencyId,
              foreignDebit: line.foreignDebit ? new Decimal(line.foreignDebit).toString() : null,
              foreignCredit: line.foreignCredit ? new Decimal(line.foreignCredit).toString() : null,
              exchangeRate: line.exchangeRate ? new Decimal(line.exchangeRate).toString() : null,
              contactId: line.contactId,
            })),
          },
        },
        include: { lines: true },
      });
    };

    if (tx) {
      // Already inside a transaction — run directly with the provided client
      return executeCreate(tx);
    } else {
      // Not in a transaction — wrap in one for atomicity
      return this.prisma.$transaction(executeCreate);
    }
  }

  async createReversal(tenantId: string, originalEntryId: string, reversalDate: Date) {
    const original = await this.prisma.journalEntry.findUnique({
      where: { id: originalEntryId },
      include: { lines: true },
    });

    if (!original || original.tenantId !== tenantId) {
      throw new NotFoundException('Original journal entry not found.');
    }
    if (original.reversalId) {
      throw new BadRequestException('This journal entry has already been reversed.');
    }

    // Validate period for reversal
    await this.periodsService.findActivePeriodByDate(tenantId, new Date(reversalDate));

    // Prepare inverted lines
    const reversalLines: CreateJournalEntryLineDto[] = original.lines.map((line) => ({
      accountId: line.accountId,
      description: `Reversal of ${original.entryNumber}`,
      // Swap debits and credits
      debit: line.credit.toString(),
      credit: line.debit.toString(),
      currencyId: line.currencyId || undefined,
      foreignDebit: line.foreignCredit?.toString(),
      foreignCredit: line.foreignDebit?.toString(),
      exchangeRate: line.exchangeRate?.toString(),
      contactId: line.contactId || undefined,
    }));

    // BUG-002 FIX: Move the count INSIDE the $transaction to prevent race conditions
    return this.prisma.$transaction(async (tx) => {
      const year = new Date(reversalDate).getUTCFullYear();
      // BUG-029 FIX: Use proper year-end boundary
      const count = await tx.journalEntry.count({
        where: {
          tenantId,
          entryDate: {
            gte: new Date(`${year}-01-01T00:00:00.000Z`),
            lt: new Date(`${year + 1}-01-01T00:00:00.000Z`),
          },
        },
      });
      const entryNumber = `REV-${year}-${String(count + 1).padStart(4, '0')}`;

      // 1. Create the reversing entry
      const reversal = await tx.journalEntry.create({
        data: {
          tenantId,
          entryNumber,
          entryDate: reversalDate,
          description: `Reversal of ${original.entryNumber}`,
          status: 'Posted',
          lines: {
            create: reversalLines.map((line) => ({
              tenantId,
              accountId: line.accountId,
              description: line.description,
              debit: line.debit ? line.debit.toString() : '0',
              credit: line.credit ? line.credit.toString() : '0',
              currencyId: line.currencyId,
              foreignDebit: line.foreignDebit,
              foreignCredit: line.foreignCredit,
              exchangeRate: line.exchangeRate,
              contactId: line.contactId,
            })),
          },
        },
      });

      // 2. Link the original to the reversal
      await tx.journalEntry.update({
        where: { id: original.id },
        data: { reversalId: reversal.id },
      });

      return reversal;
    });
  }

  async findAll(tenantId: string) {
    return this.prisma.journalEntry.findMany({
      where: { tenantId },
      include: { lines: { include: { account: true } } },
      orderBy: { entryDate: 'desc' },
    });
  }
}
