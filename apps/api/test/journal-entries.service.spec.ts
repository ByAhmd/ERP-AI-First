import { PrismaService } from '../src/database/prisma.service';
import { JournalEntriesService } from '../src/modules/accounting/journal-entries/journal-entries.service';

describe('JournalEntriesService', () => {
  const service = new JournalEntriesService({} as PrismaService);

  it('validates that total debit equals total credit using Decimal-safe logic', () => {
    const result = service.validateBalancedLines([
      {
        accountId: '11111111-1111-1111-1111-111111111111',
        debit: '0.10',
        credit: '0.00',
      },
      {
        accountId: '22222222-2222-2222-2222-222222222222',
        debit: '0.20',
        credit: '0.00',
      },
      {
        accountId: '33333333-3333-3333-3333-333333333333',
        debit: '0.00',
        credit: '0.30',
      },
    ]);

    expect(result).toEqual({
      isBalanced: true,
      totalDebit: '0.30',
      totalCredit: '0.30',
      difference: '0.00',
      message: 'Journal entry is balanced.',
    });
  });

  it('returns a clear validation result for unbalanced entries', () => {
    const result = service.validateBalancedLines([
      {
        accountId: '11111111-1111-1111-1111-111111111111',
        debit: '100.00',
        credit: '0.00',
      },
      {
        accountId: '22222222-2222-2222-2222-222222222222',
        debit: '0.00',
        credit: '90.00',
      },
    ]);

    expect(result.isBalanced).toBe(false);
    expect(result.difference).toBe('10.00');
  });
});
