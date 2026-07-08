import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { CreateInvoiceDto } from './dto/create-invoice.dto';
import { SequencesService } from '../sequences/sequences.service';
import { JournalEntriesService } from '../../accounting/journal-entries/journal-entries.service';
import { InventoryService } from '../../accounting/inventory/inventory.service';
import Decimal from 'decimal.js';

@Injectable()
export class InvoicesService {
  constructor(
    private prisma: PrismaService,
    private sequencesService: SequencesService,
    private journalEntriesService: JournalEntriesService,
    private inventoryService: InventoryService,
  ) {}

  async create(tenantId: string, dto: CreateInvoiceDto) {
    // 1. Calculate totals
    let subTotal = new Decimal(0);
    let taxTotal = new Decimal(0);

    const calculatedLines = dto.lines.map(line => {
      const lineQuantity = new Decimal(line.quantity);
      const linePrice = new Decimal(line.unitPrice);
      const lineTaxRate = new Decimal(line.taxRate || 0);

      const lineTotalBeforeTax = lineQuantity.mul(linePrice);
      const lineTaxAmount = lineTotalBeforeTax.mul(lineTaxRate.div(100));
      const lineTotal = lineTotalBeforeTax.plus(lineTaxAmount);

      subTotal = subTotal.plus(lineTotalBeforeTax);
      taxTotal = taxTotal.plus(lineTaxAmount);

      return {
        ...line,
        taxAmount: lineTaxAmount.toString(),
        total: lineTotal.toString(),
        unitPrice: linePrice.toString(),
        quantity: lineQuantity.toString(),
        taxRate: lineTaxRate.toString(),
      };
    });

    const total = subTotal.plus(taxTotal);

    // 2. Auto-generate sequence for the invoice number
    // Usually prefix is INV for Sales, PINV for Purchase, CN for Credit Note, DN for Debit Note
    let prefix = 'INV';
    if (dto.type === 'PurchaseInvoice') prefix = 'PINV';
    if (dto.type === 'CreditNote') prefix = 'CN';
    if (dto.type === 'DebitNote') prefix = 'DN';

    const invoiceNumber = await this.sequencesService.getNextSequence(tenantId, dto.type, prefix);

    return this.prisma.invoice.create({
      data: {
        tenantId,
        invoiceNumber,
        contactId: dto.contactId,
        type: dto.type,
        issueDate: new Date(dto.issueDate),
        dueDate: dto.dueDate ? new Date(dto.dueDate) : null,
        currencyId: dto.currencyId,
        exchangeRate: dto.exchangeRate ? new Decimal(dto.exchangeRate).toString() : null,
        subTotal: subTotal.toString(),
        taxTotal: taxTotal.toString(),
        total: total.toString(),
        notes: dto.notes,
        status: 'Draft',
        lines: {
          create: calculatedLines.map(l => ({
            tenantId,
            description: l.description,
            quantity: l.quantity,
            unitPrice: l.unitPrice,
            taxRate: l.taxRate,
            taxAmount: l.taxAmount,
            total: l.total,
            accountId: l.accountId,
            itemId: l.itemId,
            warehouseId: l.warehouseId,
          })),
        },
      },
      include: { lines: true },
    });
  }

  async findAll(tenantId: string) {
    return this.prisma.invoice.findMany({
      where: { tenantId },
      include: { contact: true },
      orderBy: { issueDate: 'desc' },
    });
  }

  async findOne(tenantId: string, id: string) {
    const invoice = await this.prisma.invoice.findFirst({
      where: { tenantId, id },
      include: { lines: true, contact: true },
    });
    if (!invoice) throw new NotFoundException('Invoice not found');
    return invoice;
  }

  /**
   * Approves the invoice. 
   * This generates the corresponding Journal Entry in the ledger and locks the invoice.
   */
  async approve(tenantId: string, id: string) {
    const invoice = await this.findOne(tenantId, id);

    if (invoice.status !== 'Draft' && invoice.status !== 'PendingApproval') {
      throw new BadRequestException('Only Draft or Pending invoices can be approved.');
    }

    // Determine default AR/AP account based on contact and invoice type
    const contact = invoice.contact;
    const isSales = invoice.type === 'SalesInvoice' || invoice.type === 'DebitNote';


    let controlAccountId: string;
    if (isSales) {
      if (!contact.receivableAccountId) {
        throw new BadRequestException('Contact does not have a default Accounts Receivable account set.');
      }
      controlAccountId = contact.receivableAccountId;
    } else {
      if (!contact.payableAccountId) {
        throw new BadRequestException('Contact does not have a default Accounts Payable account set.');
      }
      controlAccountId = contact.payableAccountId;
    }

    // Build the journal entry lines
    // We assume base currency for now for the ledger entry. 
    // In a fully developed multi-currency system, we would calculate base amounts using exchange rates.
    const jeLines = [];

    const exRate = invoice.exchangeRate ? new Decimal(invoice.exchangeRate) : new Decimal(1);

    // 1. Add the Control Account line (AR or AP)
    // For a Sales Invoice, we DEBIT Accounts Receivable.
    // For a Purchase Invoice, we CREDIT Accounts Payable.
    // Credit notes are inversed.
    const totalAmountBase = new Decimal(invoice.total).mul(exRate);

    jeLines.push({
      accountId: controlAccountId,
      contactId: invoice.contactId,
      description: `Invoice ${invoice.invoiceNumber} Total`,
      debit: (invoice.type === 'SalesInvoice' || invoice.type === 'DebitNote') ? totalAmountBase.toString() : 0,
      credit: (invoice.type === 'PurchaseInvoice' || invoice.type === 'CreditNote') ? totalAmountBase.toString() : 0,
    });

    // 2. Add the individual line items (Revenue or Expense)
    for (const line of invoice.lines) {
      let lineAccountId = line.accountId;
      let lineDebit = new Decimal(0);
      let lineCredit = new Decimal(0);
      const lineTotalBase = new Decimal(line.total).mul(exRate);

      if (invoice.type === 'PurchaseInvoice') {
        // Debit Expense/Inventory
        lineDebit = lineTotalBase;

        // Inventory Processing
        if (line.itemId && line.warehouseId) {
          // It's an inventory item purchase. Update WAC and increase stock.
          // Note: This relies on a Prisma transaction conceptually, but we're chaining here for simplicity.
          // For true atomicity, this whole approve block should be wrapped in this.prisma.$transaction.
          // Since InvoicesService doesn't wrap the whole approve in tx yet, we'll pass this.prisma directly to the tx arg for now.
          await this.inventoryService.processInwardMovement(
            this.prisma,
            tenantId,
            line.itemId,
            line.warehouseId,
            Number(line.quantity),
            Number(lineTotalBase),
            invoice.issueDate,
            `PINV-${invoice.invoiceNumber}`
          );
        }
      } else if (invoice.type === 'SalesInvoice') {
        // Credit Revenue
        lineCredit = lineTotalBase;

        // Inventory Processing
        if (line.itemId && line.warehouseId) {
          // Decrease stock and calculate COGS
          const { cogsAmount, cogsAccountId, inventoryAccountId } = await this.inventoryService.processOutwardMovement(
            this.prisma,
            tenantId,
            line.itemId,
            line.warehouseId,
            Number(line.quantity),
            invoice.issueDate,
            `INV-${invoice.invoiceNumber}`
          );

          // We must add additional COGS Journal Lines:
          // Debit COGS Expense
          // Credit Inventory Asset
          if (cogsAccountId && inventoryAccountId) {
            jeLines.push({
              accountId: cogsAccountId,
              contactId: invoice.contactId,
              description: `COGS for ${line.description}`,
              debit: cogsAmount.toString(),
              credit: 0,
            });
            jeLines.push({
              accountId: inventoryAccountId,
              contactId: invoice.contactId,
              description: `Inventory reduction for ${line.description}`,
              debit: 0,
              credit: cogsAmount.toString(),
            });
          }
        }
      } else if (invoice.type === 'CreditNote') {
        lineDebit = lineTotalBase;
        // Logic for returning inventory can be added later
      } else if (invoice.type === 'DebitNote') {
        lineCredit = lineTotalBase;
      }

      jeLines.push({
        accountId: lineAccountId,
        contactId: invoice.contactId,
        description: line.description,
        debit: lineDebit.toString(),
        credit: lineCredit.toString(),
      });
    }

    // Note: If tax is involved, we ideally separate the taxAmount into a Tax Payable/Receivable account.
    // For Phase 3, we are assigning the full line.total to the line.accountId.
    // In Phase 4 (ZATCA/VAT), we will split this.

    // 3. Create the Journal Entry
    const journalEntry = await this.journalEntriesService.create(tenantId, {
      entryDate: invoice.issueDate,
      description: `${invoice.type} ${invoice.invoiceNumber}`,
      lines: jeLines,
    });

    // 4. Generate ZATCA required fields
    // Placeholder UUID for ZATCA
    const { randomUUID } = require('crypto');
    const zatcaUuid = randomUUID();
    const zatcaPih = await this.sequencesService.getPreviousInvoiceHash(tenantId);

    // 5. Update the Invoice status, link Journal Entry, and set ZATCA fields
    return this.prisma.invoice.update({
      where: { id: invoice.id },
      data: {
        status: 'Approved',
        journalEntryId: journalEntry.id,
        zatcaUuid,
        zatcaPih,
        zatcaStatus: 'NotSubmitted',
      },
      include: { lines: true },
    });
  }
}

