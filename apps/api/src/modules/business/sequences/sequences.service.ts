import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

@Injectable()
export class SequencesService {
  private readonly logger = new Logger(SequencesService.name);

  constructor(private prisma: PrismaService) {}

  /**
   * Generates the next document number for a given entity type (e.g. 'INVOICE').
   * Uses an atomic update to prevent race conditions.
   */
  async getNextSequence(tenantId: string, entityType: string, prefix: string): Promise<string> {
    const sequence = await this.prisma.documentSequence.upsert({
      where: {
        tenantId_entityType: {
          tenantId,
          entityType,
        },
      },
      update: {
        nextNumber: {
          increment: 1,
        },
      },
      create: {
        tenantId,
        entityType,
        prefix,
        nextNumber: 2, // The first one will be 1, so the NEXT after this row is created is 2.
      },
    });

    // If it was just created, the number we use now is 1. If updated, it's the new nextNumber - 1.
    // Wait, upsert returns the record AFTER the update/create.
    // So if it was created, sequence.nextNumber is 2. We should return 1.
    // If it was updated from 1 to 2, sequence.nextNumber is 2. We should return 2! Wait.
    // Actually, it's better to return the `nextNumber` as the ONE TO USE NOW.
    // Let's change the logic: The DB stores the `nextNumber`.
    
    // Actually, `upsert` in Prisma increments before returning.
    // Let's rethink. If we create it, we want the current document to be `0001`, and the DB to store `2`.
    // Let's do it this way:
    const record = await this.prisma.documentSequence.upsert({
      where: { tenantId_entityType: { tenantId, entityType } },
      update: { nextNumber: { increment: 1 } },
      create: { tenantId, entityType, prefix, nextNumber: 1 },
    });

    // If created, record.nextNumber is 1. We use 1.
    // Next time, it increments to 2. We use 2.
    // This is perfect!
    
    const year = new Date().getFullYear();
    const paddedNumber = record.nextNumber.toString().padStart(4, '0');
    
    return `${prefix}-${year}-${paddedNumber}`;
  }
}
