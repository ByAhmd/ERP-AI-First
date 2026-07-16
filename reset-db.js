const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function resetDb() {
  console.log('Starting cleanup...');
  
  // Wipe Approval requests
  await prisma.approvalRequest.deleteMany({});
  
  // Wipe Payslips and Payroll
  await prisma.payslip.deleteMany({});
  await prisma.payrollRun.deleteMany({});
  
  // Wipe Payments
  await prisma.payment.deleteMany({});
  
  // Wipe Journal Entries
  await prisma.journalEntryLine.deleteMany({});
  await prisma.journalEntry.deleteMany({});
  
  // Wipe Invoices
  await prisma.invoiceLine.deleteMany({});
  await prisma.invoice.deleteMany({});
  
  // Wipe POs
  await prisma.purchaseOrderLine.deleteMany({});
  await prisma.purchaseOrder.deleteMany({});
  
  // Wipe Inventory
  await prisma.inventoryTransaction.deleteMany({});
  await prisma.inventoryBalance.deleteMany({});
  await prisma.inventoryLot.deleteMany({});
  
  // Reset Item WAC
  await prisma.item.updateMany({
    data: { weightedAverageCost: 0 }
  });

  console.log('Cleanup completed successfully!');
}

resetDb()
  .catch(e => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
