const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function run() {
  const p = await prisma.permission.count({where: {tenantId: '162bcc73-e5e8-4cf4-acb3-45a0fca5c5f7'}}); 
  console.log('Permissions count:', p);
}

run().catch(console.error).finally(() => prisma.$disconnect());
