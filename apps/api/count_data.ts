import { PrismaClient } from '@prisma/client';

async function main() {
  const prisma = new PrismaClient();
  
  const counts = {
    users: await prisma.user.count(),
    tenants: await prisma.tenant.count(),
    tenantUsers: await prisma.tenantUser.count(),
    journalEntries: await prisma.journalEntry.count(),
    invoices: await prisma.invoice.count(),
    employeeProfiles: await prisma.employeeProfile.count(),
  };

  console.log("Database Record Counts:");
  console.log(JSON.stringify(counts, null, 2));

  // Let's also check what tenants the admin user is in
  const admin = await prisma.user.findUnique({
    where: { email: 'admin@erp-ai.local' },
    include: {
      tenantUsers: {
        include: { tenant: true }
      }
    }
  });

  console.log("\nAdmin User Tenants:");
  console.log(JSON.stringify(admin?.tenantUsers.map(tu => tu.tenant.name), null, 2));

  await prisma.$disconnect();
}

main().catch(e => {
  console.error(e);
  process.exit(1);
});
