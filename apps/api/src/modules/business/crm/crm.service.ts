import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { OpportunityStage, QuoteStatus } from '@prisma/client';

@Injectable()
export class CrmService {
  constructor(private readonly prisma: PrismaService) {}

  // --- Opportunities ---

  async getOpportunities(tenantId: string) {
    return this.prisma.opportunity.findMany({
      where: { tenantId },
      include: { contact: true },
      orderBy: { createdAt: 'desc' }
    });
  }

  async createOpportunity(tenantId: string, data: any) {
    const { contactId, title, value, expectedClose, probability, notes } = data;
    return this.prisma.opportunity.create({
      data: {
        tenantId,
        contactId,
        title,
        value,
        expectedClose: expectedClose ? new Date(expectedClose) : null,
        probability: probability || 0,
        notes,
        stage: 'Prospecting'
      }
    });
  }

  async updateOpportunityStage(tenantId: string, id: string, stage: OpportunityStage) {
    return this.prisma.opportunity.update({
      where: { id, tenantId },
      data: { stage }
    });
  }

  // --- Quotes ---

  async getQuotes(tenantId: string) {
    return this.prisma.quote.findMany({
      where: { tenantId },
      include: { contact: true, opportunity: true },
      orderBy: { createdAt: 'desc' }
    });
  }

  async createQuote(tenantId: string, data: any) {
    const { opportunityId, contactId, totalAmount, issueDate, expiryDate, notes } = data;
    
    const count = await this.prisma.quote.count({ where: { tenantId } });
    const quoteNumber = `QT-${new Date().getFullYear()}-${String(count + 1).padStart(4, '0')}`;

    return this.prisma.quote.create({
      data: {
        tenantId,
        opportunityId,
        contactId,
        quoteNumber,
        totalAmount,
        issueDate: new Date(issueDate),
        expiryDate: expiryDate ? new Date(expiryDate) : null,
        notes,
        status: 'Draft'
      }
    });
  }

  async convertQuoteToInvoice(tenantId: string, quoteId: string) {
    const quote = await this.prisma.quote.findUnique({
      where: { id: quoteId, tenantId }
    });

    if (!quote) throw new NotFoundException('Quote not found');
    if (quote.status !== 'Accepted') {
      throw new BadRequestException('Only Accepted quotes can be converted to Invoices');
    }

    return this.prisma.$transaction(async (tx) => {
      const invoiceNumber = `INV-${quote.quoteNumber}`;

      const accountsReceivable = await tx.chartOfAccount.findFirst({
        where: { tenantId, type: 'Asset', name: { contains: 'Receivable' } }
      });
      const incomeAccount = await tx.chartOfAccount.findFirst({
        where: { tenantId, type: 'Revenue' } 
      });

      if (!accountsReceivable || !incomeAccount) {
         throw new BadRequestException('Default accounts for AR/Revenue not found.');
      }

      const invoice = await tx.invoice.create({
        data: {
          tenantId,
          type: 'SalesInvoice',
          contactId: quote.contactId,
          invoiceNumber,
          issueDate: new Date(),
          dueDate: new Date(new Date().setDate(new Date().getDate() + 15)),
          subTotal: quote.totalAmount,
          total: quote.totalAmount,
          status: 'Draft'
        }
      });

      return invoice;
    });
  }

  async acceptQuote(tenantId: string, quoteId: string) {
     const quote = await this.prisma.quote.update({
        where: { id: quoteId, tenantId },
        data: { status: 'Accepted' }
     });
     
     if (quote.opportunityId) {
        await this.prisma.opportunity.update({
           where: { id: quote.opportunityId },
           data: { stage: 'Won' }
        });
     }
     return quote;
  }
}
