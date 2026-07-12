const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

async function run() {
  const users = await prisma.tenantUser.findMany({ include: { tenant: true } });
  
  for (const tu of users) {
    if (tu.status !== 'Active' || !tu.roleId) {
      console.log(`Fixing tenantUser ${tu.id} for tenant ${tu.tenant.name}`);
      
      // Find the owner role for this tenant
      let ownerRole = await prisma.role.findFirst({
        where: { tenantId: tu.tenantId, name: 'Owner' }
      });
      
      if (!ownerRole) {
        console.log(`Owner role not found for tenant ${tu.tenantId}, creating it...`);
        ownerRole = await prisma.role.create({
          data: {
            tenantId: tu.tenantId,
            name: 'Owner',
            description: 'Full Access',
            isSystemRole: true,
          }
        });
        
        // Also seed all permissions for this role
        const permissions = await prisma.permission.findMany({ where: { tenantId: tu.tenantId } });
        if (permissions.length > 0) {
           await prisma.rolePermission.createMany({
             data: permissions.map(p => ({ roleId: ownerRole.id, permissionId: p.id })),
             skipDuplicates: true,
           });
        }
      }
      
      await prisma.tenantUser.update({
        where: { id: tu.id },
        data: {
          status: 'Active',
          roleId: ownerRole.id,
        }
      });
      console.log(`Fixed tenantUser ${tu.id}. Status is now Active, role is Owner.`);
    }
  }
}

run().catch(console.error).finally(() => prisma.$disconnect());
