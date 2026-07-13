const { PrismaClient } = require('@prisma/client');

const prisma = new PrismaClient();

async function main() {
  const tenants = await prisma.tenant.findMany();
  
  for (const tenant of tenants) {
    const existingRoles = await prisma.role.findMany({
      where: { tenantId: tenant.id }
    });
    
    const roleNames = existingRoles.map(r => r.name);
    
    const rolesToCreate = [
      { name: 'Admin', description: 'Full access to all features except billing' },
      { name: 'Accountant', description: 'Access to financial records, journals, and reports' },
      { name: 'Member', description: 'Basic access to view dashboard and personal info' }
    ];
    
    for (const role of rolesToCreate) {
      if (!roleNames.includes(role.name)) {
        await prisma.role.create({
          data: {
            tenantId: tenant.id,
            name: role.name,
            description: role.description,
            isSystemRole: true,
          }
        });
        console.log(`Created role ${role.name} for tenant ${tenant.name}`);
      }
    }
  }
}

main()
  .catch(e => console.error(e))
  .finally(() => prisma.$disconnect());
