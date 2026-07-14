import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { Decimal } from 'decimal.js';

@Injectable()
export class PurchaseOrdersService {
  constructor(private readonly prisma: PrismaService) {}

  async createPO(tenantId: string, data: any) {
    const { contactId, issueDate, deliveryDate, notes, lines } = data;
    
    // Auto-generate PO number
    const count = await this.prisma.purchaseOrder.count({ where: { tenantId } });
    const poNumber = `PO-${new Date().getFullYear()}-${String(count + 1).padStart(4, '0')}`;

    let totalAmount = new Decimal(0);
    const poLines = lines.map((line: any) => {
      const qty = new Decimal(line.quantity);
      const price = new Decimal(line.unitPrice);
      const total = qty.mul(price);
      totalAmount = totalAmount.plus(total);
      
      return {
        description: line.description,
        quantity: qty,
        unitPrice: price,
        total: total,
        itemId: line.itemId
      };
    });

    return this.prisma.purchaseOrder.create({
      data: {
        tenantId,
        poNumber,
        contactId,
        issueDate: new Date(issueDate),
        deliveryDate: deliveryDate ? new Date(deliveryDate) : null,
        totalAmount,
        notes,
        status: 'Draft',
        lines: { create: poLines }
      },
      include: { lines: true }
    });
  }

  async receiveGoods(tenantId: string, poId: string, warehouseId: string) {
    const po = await this.prisma.purchaseOrder.findUnique({
      where: { id: poId, tenantId },
      include: { lines: true }
    });

    if (!po) throw new NotFoundException('Purchase Order not found');
    if (po.status !== 'Sent' && po.status !== 'Draft') {
      throw new BadRequestException(`Cannot receive goods for PO in status: ${po.status}`);
    }

    return this.prisma.$transaction(async (tx) => {
      // Create inventory transactions for each line that has an itemId
      for (const line of po.lines) {
        if (line.itemId) {
          await tx.inventoryTransaction.create({
            data: {
              tenantId,
              itemId: line.itemId,
              warehouseId: warehouseId,
              type: 'Purchase',
              quantity: line.quantity,
              unitCost: line.unitPrice,
              reference: po.id,
              date: new Date()
            }
          });
        }
      }

      return tx.purchaseOrder.update({
        where: { id: po.id },
        data: { status: 'Received' }
      });
    });
  }

  async convertToBill(tenantId: string, poId: string) {
    const po = await this.prisma.purchaseOrder.findUnique({
      where: { id: poId, tenantId },
      include: { lines: true }
    });

    if (!po) throw new NotFoundException('Purchase Order not found');
    if (po.status === 'Billed' || po.status === 'Cancelled') {
      throw new BadRequestException('PO already billed or cancelled');
    }

    return this.prisma.$transaction(async (tx) => {
      const invoiceNumber = `BILL-${po.poNumber}`;

      // In a real system, we look up accounts based on the supplier/contact or standard payables
      const accountsPayable = await tx.chartOfAccount.findFirst({
        where: { tenantId, type: 'Liability', name: { contains: 'Payable' } }
      });
      const expenseAccount = await tx.chartOfAccount.findFirst({
        where: { tenantId, type: 'Expense' } // Simplification for MVP
      });

      if (!accountsPayable || !expenseAccount) {
         throw new BadRequestException('Default accounts for AP/Expenses not found.');
      }

      const invoice = await tx.invoice.create({
        data: {
          tenantId,
          type: 'PurchaseInvoice',
          contactId: po.contactId,
          invoiceNumber,
          issueDate: new Date(),
          dueDate: new Date(new Date().setDate(new Date().getDate() + 30)),
          subTotal: po.totalAmount,
          total: po.totalAmount,
          status: 'Draft'
        }
      });

      await tx.purchaseOrder.update({
        where: { id: po.id },
        data: { status: 'Billed' }
      });

      return invoice;
    });
  }
}
