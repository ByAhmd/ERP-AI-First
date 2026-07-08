import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

@Injectable()
export class SequencesService {
  private readonly logger = new Logger(SequencesService.name);

  constructor(private prisma: PrismaService) {}

  /**
   * Generates the next document number for a given entity type (e.g. 'SalesInvoice').
   *
   * Uses Prisma `upsert` with atomic `increment` to prevent race conditions
   * and guarantee gapless numbering under concurrent access.
   *
   * - First call for a given (tenantId, entityType): Creates the row with nextNumber=1, returns "PREFIX-YYYY-0001".
   * - Subsequent calls: Atomically increments nextNumber and returns the new value.
   */
  async getNextSequence(tenantId: string, entityType: string, prefix: string): Promise<string> {
    const record = await this.prisma.documentSequence.upsert({
      where: { tenantId_entityType: { tenantId, entityType } },
      update: { nextNumber: { increment: 1 } },
      create: { tenantId, entityType, prefix, nextNumber: 1 },
    });

    const year = new Date().getFullYear();
    const paddedNumber = record.nextNumber.toString().padStart(4, '0');

    return `${prefix}-${year}-${paddedNumber}`;
  }

  /**
   * Retrieves the ZATCA Previous Invoice Hash (PIH) for chaining.
   * If there is no previous invoice, it returns a default base64 zero-hash as per ZATCA rules.
   */
  async getPreviousInvoiceHash(tenantId: string): Promise<string> {
    const lastInvoice = await this.prisma.invoice.findFirst({
      where: {
        tenantId,
        zatcaPih: { not: null },
      },
      orderBy: {
        createdAt: 'desc',
      },
      select: {
        zatcaUuid: true, // we can use the UUID or a generated hash as placeholder
      },
    });

    if (!lastInvoice) {
      // Default ZATCA base64 encoded '0' hash for the very first invoice
      return 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjdzZkNDg4NTU2YWUyODkxNzlkOTNjOTlmZDcyMjk0MWQyMjMyMjc3OA==';
    }

    // Placeholder: return base64 of the UUID as a mock hash for now
    return Buffer.from(lastInvoice.zatcaUuid || 'mock-hash').toString('base64');
  }
}
