import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { GetVatReturnDto } from './dto/get-vat-return.dto';
import Decimal from 'decimal.js';

@Injectable()
export class VatService {
  constructor(private readonly prisma: PrismaService) {}

  async calculateVatReturn(tenantId: string, dto: GetVatReturnDto) {
    const { startDate, endDate } = dto;

    const start = new Date(startDate);
    start.setUTCHours(0, 0, 0, 0);

    const end = new Date(endDate);
    end.setUTCHours(23, 59, 59, 999);

    // Fetch all approved invoices within the date range
    const invoices = await this.prisma.invoice.findMany({
      where: {
        tenantId,
        issueDate: {
          gte: start,
          lte: end,
        },
        status: { in: ['Approved', 'Paid'] },
      },
      select: {
        type: true,
        taxTotal: true,
      },
    });

    let outputVat = new Decimal(0); // From SalesInvoices & DebitNotes
    let inputVat = new Decimal(0); // From PurchaseInvoices & CreditNotes

    for (const inv of invoices) {
      if (inv.type === 'SalesInvoice' || inv.type === 'DebitNote') {
        outputVat = outputVat.plus(inv.taxTotal);
      } else if (inv.type === 'PurchaseInvoice' || inv.type === 'CreditNote') {
        inputVat = inputVat.plus(inv.taxTotal);
      }
    }

    const netVatLiability = outputVat.minus(inputVat);

    return {
      period: {
        startDate,
        endDate,
      },
      outputVat: outputVat.toNumber(),
      inputVat: inputVat.toNumber(),
      netVatLiability: netVatLiability.toNumber(),
    };
  }
}
