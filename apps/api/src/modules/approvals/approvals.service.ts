import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';
import { EventEmitter2 } from '@nestjs/event-emitter';

@Injectable()
export class ApprovalsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly eventEmitter: EventEmitter2
  ) {}

  async getPendingApprovals(tenantId: string) {
    return this.prisma.approvalRequest.findMany({
      where: {
        tenantId,
        status: 'Pending',
      },
      include: {
        requestedBy: {
          select: { fullName: true, email: true },
        },
      },
      orderBy: { createdAt: 'desc' },
    });
  }

  async getApprovalDetails(tenantId: string, id: string) {
    const request = await this.prisma.approvalRequest.findUnique({
      where: { id, tenantId },
      include: {
        requestedBy: { select: { fullName: true, email: true } },
      }
    });

    if (!request) throw new NotFoundException('Approval request not found');

    let details = null;
    if (request.entityType === 'Payment') {
      const payment = await this.prisma.payment.findUnique({
        where: { id: request.entityId },
        include: { contact: true }
      });
      if (payment) {
        details = {
          amount: payment.amount,
          paymentDate: payment.paymentDate,
          contactName: payment.contact?.name,
          paymentNumber: payment.paymentNumber,
          notes: payment.notes
        };
      }
    } else if (request.entityType === 'PurchaseOrder') {
      const po = await this.prisma.purchaseOrder.findUnique({
        where: { id: request.entityId },
        include: { contact: true }
      });
      if (po) {
        details = {
          poNumber: po.poNumber,
          totalAmount: po.totalAmount,
          issueDate: po.issueDate,
          contactName: po.contact?.name,
          notes: po.notes
        };
      }
    } else if (request.entityType === 'PayrollRun') {
      const pr = await this.prisma.payrollRun.findUnique({
        where: { id: request.entityId },
      });
      if (pr) {
        details = {
          periodName: pr.periodName,
          totalGross: pr.totalGross,
          totalNet: pr.totalNet,
          totalDeductions: pr.totalDeductions
        };
      }
    }

    return { request, details };
  }

  async approveRequest(tenantId: string, id: string, approverId: string, comments?: string) {
    const request = await this.prisma.approvalRequest.findUnique({
      where: { id, tenantId },
    });

    if (!request) throw new NotFoundException('Approval request not found');

    // BUG-014 FIX: Guard against double-processing. If this is called twice,
    // the event fires twice which would double-post journal entries.
    if (request.status !== 'Pending') {
      throw new BadRequestException(`This approval request has already been ${request.status.toLowerCase()}.`);
    }

    const updatedRequest = await this.prisma.approvalRequest.update({
      where: { id },
      data: {
        status: 'Approved',
        approverId,
        comments,
      },
    });

    this.eventEmitter.emit('approval.approved', {
      tenantId,
      entityType: request.entityType,
      entityId: request.entityId,
      comments
    });

    return updatedRequest;
  }

  async rejectRequest(tenantId: string, id: string, approverId: string, comments?: string) {
    const request = await this.prisma.approvalRequest.findUnique({
      where: { id, tenantId },
    });

    if (!request) throw new NotFoundException('Approval request not found');

    // BUG-014 FIX: Guard against double-processing
    if (request.status !== 'Pending') {
      throw new BadRequestException(`This approval request has already been ${request.status.toLowerCase()}.`);
    }

    const updatedRequest = await this.prisma.approvalRequest.update({
      where: { id },
      data: {
        status: 'Rejected',
        approverId,
        comments,
      },
    });

    this.eventEmitter.emit('approval.rejected', {
      tenantId,
      entityType: request.entityType,
      entityId: request.entityId,
      comments
    });

    return updatedRequest;
  }
}
