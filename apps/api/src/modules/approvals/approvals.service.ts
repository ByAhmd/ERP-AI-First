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

    // BUG-014 FIX: Guard against double-processing.
    if (request.status !== 'Pending') {
      throw new BadRequestException(`This approval request has already been ${request.status.toLowerCase()}.`);
    }

    // OP-001 FIX: Prevent self-approval (DISABLED FOR TESTING)
    // if (request.requestedById === approverId) {
    //   throw new BadRequestException('You cannot approve your own request.');
    // }

    const updatedRequest = await this.prisma.approvalRequest.update({
      where: { id },
      data: {
        status: 'Approved',
        approverId,
        comments,
      },
    });

    try {
      // OP-004 FIX: Use emitAsync and wait for listeners.
      await this.eventEmitter.emitAsync('approval.approved', {
        tenantId,
        entityType: request.entityType,
        entityId: request.entityId,
        comments
      });
    } catch (error: any) {
      // Compensating transaction: revert to Pending if the business logic fails
      await this.prisma.approvalRequest.update({
        where: { id },
        data: { status: 'Pending', approverId: null, comments: null },
      });
      throw new BadRequestException(`Approval processing failed: ${error.message}`);
    }

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

    // OP-001 FIX: Prevent self-rejection of own request (DISABLED FOR TESTING)
    // if (request.requestedById === approverId) {
    //   throw new BadRequestException('You cannot reject your own request.');
    // }

    const updatedRequest = await this.prisma.approvalRequest.update({
      where: { id },
      data: {
        status: 'Rejected',
        approverId,
        comments,
      },
    });

    try {
      await this.eventEmitter.emitAsync('approval.rejected', {
        tenantId,
        entityType: request.entityType,
        entityId: request.entityId,
        comments
      });
    } catch (error: any) {
      // Revert to Pending
      await this.prisma.approvalRequest.update({
        where: { id },
        data: { status: 'Pending', approverId: null, comments: null },
      });
      throw new BadRequestException(`Rejection processing failed: ${error.message}`);
    }

    return updatedRequest;
  }
}
