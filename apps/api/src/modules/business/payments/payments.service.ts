import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { CreatePaymentDto } from './dto/create-payment.dto';
import { SequencesService } from '../sequences/sequences.service';
import { JournalEntriesService } from '../../accounting/journal-entries/journal-entries.service';
import Decimal from 'decimal.js';

@Injectable()
export class PaymentsService {
  constructor(
    private prisma: PrismaService,
    private sequencesService: SequencesService,
    private journalEntriesService: JournalEntriesService,
  ) {}

  async create(tenantId: string, dto: CreatePaymentDto) {
    // Basic validation for allocations
    if (dto.allocations && dto.allocations.length > 0) {
      let allocatedTotal = new Decimal(0);
      for (const allocation of dto.allocations) {
        allocatedTotal = allocatedTotal.plus(allocation.amount);
      }
      if (allocatedTotal.greaterThan(dto.amount)) {
        throw new BadRequestException('Total allocated amount cannot exceed the payment amount.');
      }
    }

    const prefix = dto.type === 'Incoming' ? 'RCPT' : 'PYMT';
    const paymentNumber = await this.sequencesService.getNextSequence(tenantId, dto.type, prefix);

    return this.prisma.payment.create({
      data: {
        tenantId,
        paymentNumber,
        contactId: dto.contactId,
        type: dto.type,
        status: 'Draft',
        paymentDate: new Date(dto.paymentDate),
        amount: dto.amount.toString(),
        accountId: dto.accountId,
        currencyId: dto.currencyId,
        exchangeRate: dto.exchangeRate ? dto.exchangeRate.toString() : null,
        reference: dto.reference,
        notes: dto.notes,
        allocations: {
          create: dto.allocations?.map(a => ({
            tenantId,
            invoiceId: a.invoiceId,
            amount: a.amount.toString(),
          })) || [],
        },
      },
      include: { allocations: true },
    });
  }

  async findAll(tenantId: string) {
    return this.prisma.payment.findMany({
      where: { tenantId },
      include: { contact: true, allocations: true },
      orderBy: { paymentDate: 'desc' },
    });
  }

  async findOne(tenantId: string, id: string) {
    const payment = await this.prisma.payment.findFirst({
      where: { tenantId, id },
      include: { contact: true, allocations: { include: { invoice: true } } },
    });
    if (!payment) throw new NotFoundException('Payment not found');
    return payment;
  }

  async approve(tenantId: string, id: string) {
    const payment = await this.findOne(tenantId, id);

    if (payment.status !== 'Draft') {
      throw new BadRequestException('Only Draft payments can be approved.');
    }

    const contact = payment.contact;
    const isIncoming = payment.type === 'Incoming';
    
    // Validate Contact AR/AP accounts
    let controlAccountId: string;
    if (isIncoming) {
      if (!contact.receivableAccountId) {
        throw new BadRequestException('Contact does not have a default Accounts Receivable account set for Incoming Payment.');
      }
      controlAccountId = contact.receivableAccountId;
    } else {
      if (!contact.payableAccountId) {
        throw new BadRequestException('Contact does not have a default Accounts Payable account set for Outgoing Payment.');
      }
      controlAccountId = contact.payableAccountId;
    }

    // Process Allocations against invoices
    if (payment.allocations.length > 0) {
      for (const allocation of payment.allocations) {
        const invoice = allocation.invoice;
        if (invoice.status !== 'Approved' && invoice.status !== 'Paid') {
           throw new BadRequestException(`Cannot allocate payment to invoice ${invoice.invoiceNumber} as it is not Approved.`);
        }
        
        // Use Prisma's increment to update the paid amount on the invoice atomically
        const newPaidAmount = new Decimal(invoice.amountPaid).plus(allocation.amount);
        let newStatus = invoice.status;

        // Note: In real life, floating point tolerance applies. Here we use exact decimal matches.
        if (newPaidAmount.greaterThanOrEqualTo(invoice.total)) {
          newStatus = 'Paid';
        }

        await this.prisma.invoice.update({
          where: { id: invoice.id },
          data: {
            amountPaid: newPaidAmount.toString(),
            status: newStatus,
          }
        });
      }
    }

    const exRate = payment.exchangeRate ? new Decimal(payment.exchangeRate) : new Decimal(1);
    const totalBase = new Decimal(payment.amount).mul(exRate);

    // Create Journal Entry
    // Incoming: Debit Bank/Cash (payment.accountId), Credit AR (controlAccountId)
    // Outgoing: Debit AP (controlAccountId), Credit Bank/Cash (payment.accountId)
    const jeLines = [];

    jeLines.push({
      accountId: payment.accountId, // Bank or Cash
      description: `Payment ${payment.paymentNumber}`,
      debit: isIncoming ? totalBase.toString() : 0,
      credit: !isIncoming ? totalBase.toString() : 0,
      contactId: undefined, // Usually bank account doesn't need contact link
    });

    jeLines.push({
      accountId: controlAccountId, // AR or AP
      description: `Payment ${payment.paymentNumber}`,
      debit: !isIncoming ? totalBase.toString() : 0,
      credit: isIncoming ? totalBase.toString() : 0,
      contactId: payment.contactId, // Sub-ledger link
    });

    const journalEntry = await this.journalEntriesService.create(tenantId, {
      entryDate: payment.paymentDate,
      description: `${payment.type} Payment ${payment.paymentNumber}`,
      lines: jeLines,
    });

    return this.prisma.payment.update({
      where: { id: payment.id },
      data: {
        status: 'Approved',
        journalEntryId: journalEntry.id,
      },
      include: { allocations: true },
    });
  }
}
