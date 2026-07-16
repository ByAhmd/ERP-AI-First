const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function fixCogs() {
  const tenantId = 'd3a1a467-54dc-4dfa-9279-0ed8eaaf04f7';
  
  // 1. Create missing accounts
  let invAcc = await prisma.chartOfAccount.findFirst({
    where: { tenantId, type: 'Asset', name: { contains: 'Inventory', mode: 'insensitive' } }
  });
  if (!invAcc) {
    invAcc = await prisma.chartOfAccount.create({
      data: { tenantId, type: 'Asset', name: 'Inventory Asset', code: '1130' }
    });
    console.log('Created Inventory Asset account.');
  }

  let cogsAcc = await prisma.chartOfAccount.findFirst({
    where: { tenantId, type: 'Expense', name: { contains: 'Cost of Goods', mode: 'insensitive' } }
  });
  if (!cogsAcc) {
    cogsAcc = await prisma.chartOfAccount.create({
      data: { tenantId, type: 'Expense', name: 'Cost of Goods Sold', code: '5130' }
    });
    console.log('Created Cost of Goods Sold account.');
  }

  // 2. Add COGS lines to JE-2026-0002
  const je = await prisma.journalEntry.findFirst({
    where: { tenantId, entryNumber: 'JE-2026-0002' },
    include: { lines: true }
  });

  if (je) {
    const hasCogs = je.lines.some(l => l.accountId === cogsAcc.id);
    if (!hasCogs) {
      await prisma.journalEntryLine.createMany({
        data: [
          {
            tenantId,
            journalEntryId: je.id,
            accountId: cogsAcc.id,
            description: 'COGS for 2 laptops',
            debit: 6000,
            credit: 0
          },
          {
            tenantId,
            journalEntryId: je.id,
            accountId: invAcc.id,
            description: 'Inventory reduction for 2 laptops',
            debit: 0,
            credit: 6000
          }
        ]
      });
      console.log('Added missing COGS lines to JE-2026-0002.');
    }
  }

  console.log('Fix completed.');
}

fixCogs()
  .catch(e => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
