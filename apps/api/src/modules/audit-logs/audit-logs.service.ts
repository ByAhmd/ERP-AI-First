import { Injectable } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PrismaService } from '../../database/prisma.service';

export interface CreateAuditLogInput {
  tenantId?: string;
  actorUserId?: string;
  action: string;
  entityType: string;
  entityId?: string;
  metadata?: Prisma.InputJsonValue;
  ipAddress?: string;
  userAgent?: string;
}

@Injectable()
export class AuditLogsService {
  constructor(private readonly prisma: PrismaService) {}

  create(input: CreateAuditLogInput) {
    // TODO: Call this from sensitive operations: auth, roles, permissions, posting journals, tax exports.
    return this.prisma.auditLog.create({
      data: input,
    });
  }

  listRecent() {
    // TODO: Add tenant scoping, filters, pagination, and permission checks.
    return this.prisma.auditLog.findMany({
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
  }
}
