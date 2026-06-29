import { BadRequestException, Injectable } from '@nestjs/common';
import Decimal from 'decimal.js';
import { PrismaService } from '../../../database/prisma.service';
import { CreateDraftJournalEntryDto } from './dto/create-draft-journal-entry.dto';
import { JournalEntryLineAmountDto } from './dto/journal-entry-line-amount.dto';

export interface JournalEntryBalanceValidationResult {
  isBalanced: boolean;
  totalDebit: string;
  totalCredit: string;
  difference: string;
  message: string;
}

@Injectable()
export class JournalEntriesService {
  constructor(private readonly prisma: PrismaService) {}

  async createDraft(dto: CreateDraftJournalEntryDto) {
    const validation = this.validateBalancedLines(dto.lines);

    if (!validation.isBalanced) {
      throw new BadRequestException(validation.message);
    }

    // TODO: Add accounting period checks, account status checks, numbering policy, and audit logging.
    return this.prisma.journalEntry.create({
      data: {
        tenantId: dto.tenantId,
        entryNumber: dto.entryNumber,
        entryDate: new Date(dto.entryDate),
        description: dto.description,
        status: 'Draft',
        lines: {
          create: dto.lines.map((line) => ({
            tenantId: dto.tenantId,
            accountId: line.accountId,
            description: line.description,
            debit: line.debit,
            credit: line.credit,
          })),
        },
      },
      include: { lines: true },
    });
  }

  validateBalancedLines(lines: JournalEntryLineAmountDto[]): JournalEntryBalanceValidationResult {
    const totalDebit = lines.reduce(
      (sum, line) => sum.plus(new Decimal(line.debit ?? '0')),
      new Decimal(0),
    );
    const totalCredit = lines.reduce(
      (sum, line) => sum.plus(new Decimal(line.credit ?? '0')),
      new Decimal(0),
    );
    const difference = totalDebit.minus(totalCredit).abs();
    const isBalanced = difference.equals(0);

    return {
      isBalanced,
      totalDebit: totalDebit.toFixed(2),
      totalCredit: totalCredit.toFixed(2),
      difference: difference.toFixed(2),
      message: isBalanced
        ? 'Journal entry is balanced.'
        : 'Journal entry is not balanced. Total debit must equal total credit.',
    };
  }
}
