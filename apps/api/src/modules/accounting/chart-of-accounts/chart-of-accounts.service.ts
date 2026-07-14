import { Injectable, BadRequestException, ConflictException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

export interface CreateAccountDto {
  code: string;
  name: string;
  type: 'Asset' | 'Liability' | 'Equity' | 'Revenue' | 'Expense';
  parentId?: string;
  currencyId?: string;
}

@Injectable()
export class ChartOfAccountsService {
  constructor(private readonly prisma: PrismaService) {}

  async createAccount(tenantId: string, dto: CreateAccountDto) {
    const existingCode = await this.prisma.chartOfAccount.findUnique({
      where: { tenantId_code: { tenantId, code: dto.code } },
    });

    if (existingCode) {
      throw new ConflictException(`Account code ${dto.code} already exists.`);
    }

    if (dto.parentId) {
      const parent = await this.prisma.chartOfAccount.findUnique({
        where: { id: dto.parentId },
      });

      if (!parent || parent.tenantId !== tenantId) {
        throw new BadRequestException('Parent account not found or does not belong to tenant.');
      }

      // Ensure the child type matches the parent type
      if (parent.type !== dto.type) {
        throw new BadRequestException(`Child account type (${dto.type}) must match parent account type (${parent.type}).`);
      }
    }

    return this.prisma.chartOfAccount.create({
      data: {
        tenantId,
        code: dto.code,
        name: dto.name,
        type: dto.type,
        parentId: dto.parentId,
        currencyId: dto.currencyId,
      },
    });
  }

  /**
   * Retrieves accounts in a flat list, but includes parent/child relations.
   */
  async listAccounts(tenantId: string) {
    return this.prisma.chartOfAccount.findMany({
      where: { tenantId },
      include: {
        children: {
           select: { id: true, code: true, name: true, type: true }
        },
      },
      orderBy: { code: 'asc' },
    });
  }

  /**
   * Retrieves the hierarchical tree of accounts.
   */
  async getAccountTree(tenantId: string) {
    const allAccounts = await this.listAccounts(tenantId);
    
    const accountMap = new Map();
    allAccounts.forEach(acc => accountMap.set(acc.id, { ...acc, children: [] }));
    
    const tree: any[] = [];
    
    accountMap.forEach(acc => {
      if (acc.parentId) {
        const parent = accountMap.get(acc.parentId);
        if (parent) {
          parent.children.push(acc);
        } else {
          // Fallback if parent missing (shouldn't happen with FKs)
          tree.push(acc);
        }
      } else {
        tree.push(acc);
      }
    });

    return tree;
  }

  /**
   * Prevents posting to accounts that have children (summary accounts).
   */
  async validateAccountForPosting(tenantId: string, accountId: string) {
    const account = await this.prisma.chartOfAccount.findUnique({
      where: { id: accountId },
      include: {
        _count: {
          select: { children: true }
        }
      }
    });

    if (!account || account.tenantId !== tenantId || account.status !== 'Active') {
      throw new BadRequestException(`Account ${accountId} is not valid or active.`);
    }

    if (account._count.children > 0) {
      throw new BadRequestException(`Cannot post to summary account ${account.code} (${account.name}).`);
    }

    return account;
  }

  async seedSmeTemplate(tenantId: string, txClient?: any) {
    const template = [
      // Assets (1000)
      { code: '1000', name: 'Assets', type: 'Asset' as const, parentCode: null },
      { code: '1100', name: 'Current Assets', type: 'Asset' as const, parentCode: '1000' },
      { code: '1110', name: 'Cash and Cash Equivalents', type: 'Asset' as const, parentCode: '1100' },
      { code: '1111', name: 'Operating Bank Account', type: 'Asset' as const, parentCode: '1110' },
      { code: '1120', name: 'Accounts Receivable', type: 'Asset' as const, parentCode: '1100' },
      
      // Liabilities (2000)
      { code: '2000', name: 'Liabilities', type: 'Liability' as const, parentCode: null },
      { code: '2100', name: 'Current Liabilities', type: 'Liability' as const, parentCode: '2000' },
      { code: '2110', name: 'Accounts Payable', type: 'Liability' as const, parentCode: '2100' },
      { code: '2120', name: 'VAT Payable', type: 'Liability' as const, parentCode: '2100' },
      
      // Equity (3000)
      { code: '3000', name: 'Equity', type: 'Equity' as const, parentCode: null },
      { code: '3100', name: 'Share Capital', type: 'Equity' as const, parentCode: '3000' },
      { code: '3200', name: 'Retained Earnings', type: 'Equity' as const, parentCode: '3000' },
      
      // Revenue (4000)
      { code: '4000', name: 'Revenue', type: 'Revenue' as const, parentCode: null },
      { code: '4100', name: 'Sales Revenue', type: 'Revenue' as const, parentCode: '4000' },
      { code: '4110', name: 'Services Revenue', type: 'Revenue' as const, parentCode: '4000' },
      
      // Expenses (5000)
      { code: '5000', name: 'Expenses', type: 'Expense' as const, parentCode: null },
      { code: '5100', name: 'Operating Expenses', type: 'Expense' as const, parentCode: '5000' },
      { code: '5110', name: 'Salaries and Wages', type: 'Expense' as const, parentCode: '5100' },
      { code: '5120', name: 'Rent Expense', type: 'Expense' as const, parentCode: '5100' },
    ];

    const client = txClient || this.prisma;
    const existingAccounts = await client.chartOfAccount.findMany({ where: { tenantId } });
    const existingCodes = new Set(existingAccounts.map((a: any) => a.code));

    const executeSeeding = async (tx: any) => {
      const createdMap = new Map<string, string>();
      
      // Add existing accounts to the created map so children can find them
      if (existingAccounts.length > 0) {
        existingAccounts.forEach((a: any) => createdMap.set(a.code, a.id));
      }

      for (const acc of template) {
        if (existingCodes.has(acc.code)) {
          continue; // Skip if already exists
        }

        let parentId = null;
        if (acc.parentCode) {
           parentId = createdMap.get(acc.parentCode);
           if (!parentId) throw new Error(`Parent code ${acc.parentCode} not found during seeding.`);
        }

        const created = await tx.chartOfAccount.create({
          data: {
            tenantId,
            code: acc.code,
            name: acc.name,
            type: acc.type,
            parentId,
          }
        });

        createdMap.set(acc.code, created.id);
      }
    };

    if (txClient) {
      return executeSeeding(txClient);
    } else {
      return this.prisma.$transaction(executeSeeding);
    }
  }
}
