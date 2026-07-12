const { PrismaClient } = require('@prisma/client');
const { ALL_PERMISSION_KEYS, PERMISSION_DESCRIPTIONS } = require('@erp-ai/shared');

const prisma = new PrismaClient();

async function run() {
  const tenantId = '162bcc73-e5e8-4cf4-acb3-45a0fca5c5f7';
  
  console.log('Seeding permissions for broken tenant...');
  
  await prisma.permission.createMany({
    data: ALL_PERMISSION_KEYS.map((key) => ({
      tenantId: tenantId,
      key,
      description: PERMISSION_DESCRIPTIONS[key] ?? key,
    })),
    skipDuplicates: true,
  });

  const permissions = await prisma.permission.findMany({ where: { tenantId } });
  console.log(`Seeded ${permissions.length} permissions.`);

  const ownerRole = await prisma.role.findFirst({ where: { tenantId, name: 'Owner' } });
  if (ownerRole) {
    await prisma.rolePermission.createMany({
      data: permissions.map(p => ({ roleId: ownerRole.id, permissionId: p.id })),
      skipDuplicates: true,
    });
    console.log('Assigned all permissions to Owner role.');
  } else {
    console.log('Owner role not found!');
  }
}

run().catch(console.error).finally(() => prisma.$disconnect());
