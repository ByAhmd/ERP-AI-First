const fs = require('fs');

let schema = fs.readFileSync('apps/api/prisma/schema.prisma', 'utf8');

// 1. RolePermission
schema = schema.replace(
`model RolePermission {
  id           String     @id @default(uuid())
  roleId       String
  permissionId String
  createdAt    DateTime   @default(now())
  updatedAt    DateTime   @updatedAt
  role         Role       @relation(fields: [roleId], references: [id])
  permission   Permission @relation(fields: [permissionId], references: [id])

  @@unique([roleId, permissionId])
}`,
`model RolePermission {
  id           String     @id @default(uuid())
  tenantId     String
  roleId       String
  permissionId String
  createdAt    DateTime   @default(now())
  updatedAt    DateTime   @updatedAt
  tenant       Tenant     @relation(fields: [tenantId], references: [id])
  role         Role       @relation(fields: [roleId], references: [id], onDelete: Cascade)
  permission   Permission @relation(fields: [permissionId], references: [id], onDelete: Cascade)

  @@unique([roleId, permissionId])
  @@index([tenantId])
}`);

// 2. JournalEntryLine cascade and restrict
schema = schema.replace(
`  journalEntry     JournalEntry    @relation(fields: [journalEntryId], references: [id])`,
`  journalEntry     JournalEntry    @relation(fields: [journalEntryId], references: [id], onDelete: Cascade)`);

schema = schema.replace(
`  contact          Contact?        @relation(fields: [contactId], references: [id])`,
`  contact          Contact?        @relation(fields: [contactId], references: [id], onDelete: Restrict)`);

// 3. Invoice relations
schema = schema.replace(
`  contact        Contact             @relation(fields: [contactId], references: [id])`,
`  contact        Contact             @relation(fields: [contactId], references: [id], onDelete: Restrict)
  journalEntry   JournalEntry?       @relation(fields: [journalEntryId], references: [id])`);

// 4. InvoiceLine relations
schema = schema.replace(
`  invoice     Invoice    @relation(fields: [invoiceId], references: [id], onDelete: Cascade)
  item        Item?      @relation(fields: [itemId], references: [id])
  warehouse   Warehouse? @relation(fields: [warehouseId], references: [id])`,
`  invoice     Invoice    @relation(fields: [invoiceId], references: [id], onDelete: Cascade)
  item        Item?      @relation(fields: [itemId], references: [id], onDelete: Restrict)
  warehouse   Warehouse? @relation(fields: [warehouseId], references: [id], onDelete: Restrict)
  account     ChartOfAccount @relation(fields: [accountId], references: [id])
  taxCode     TaxCode?       @relation(fields: [taxCodeId], references: [id])`);

// 5. Payment relations
schema = schema.replace(
`  allocations    PaymentAllocation[]`,
`  allocations    PaymentAllocation[]
  accountRef     ChartOfAccount      @relation("PaymentAccount", fields: [accountId], references: [id])
  whtAccountRef  ChartOfAccount?     @relation("PaymentWhtAccount", fields: [whtAccountId], references: [id])
  journalEntry   JournalEntry?       @relation(fields: [journalEntryId], references: [id])`);

// 6. FixedAsset relations
schema = schema.replace(
`  schedules             DepreciationSchedule[]`,
`  schedules             DepreciationSchedule[]
  assetAccount          ChartOfAccount       @relation("AssetAccount", fields: [assetAccountId], references: [id])
  depreciationAccount   ChartOfAccount       @relation("DepreciationAccount", fields: [depreciationAccountId], references: [id])
  expenseAccount        ChartOfAccount       @relation("ExpenseAccount", fields: [expenseAccountId], references: [id])`);

// 7. Item relations
schema = schema.replace(
`  InventoryTransaction InventoryTransaction[]`,
`  InventoryTransaction InventoryTransaction[]
  inventoryAccount     ChartOfAccount?        @relation("InventoryAccount", fields: [inventoryAccountId], references: [id])
  cogsAccount          ChartOfAccount?        @relation("CogsAccount", fields: [cogsAccountId], references: [id])
  revenueAccount       ChartOfAccount?        @relation("RevenueAccount", fields: [revenueAccountId], references: [id])`);

// 8. InventoryTransaction relations
schema = schema.replace(
`  warehouse      Warehouse                @relation(fields: [warehouseId], references: [id])`,
`  warehouse      Warehouse                @relation(fields: [warehouseId], references: [id])
  journalEntry   JournalEntry?            @relation(fields: [journalEntryId], references: [id])`);

// 9. PayrollRun relations
schema = schema.replace(
`  payslips              Payslip[]`,
`  payslips              Payslip[]
  journalEntry          JournalEntry? @relation(fields: [journalEntryId], references: [id])`);

// 10. BankStatementTransaction
schema = schema.replace(
`model BankStatementTransaction {
  id              String        @id @default(uuid())
  bankStatementId String`,
`model BankStatementTransaction {
  id              String        @id @default(uuid())
  tenantId        String
  bankStatementId String`);

schema = schema.replace(
`  bankStatement   BankStatement @relation(fields: [bankStatementId], references: [id], onDelete: Cascade)

  @@index([bankStatementId])
}`,
`  bankStatement   BankStatement @relation(fields: [bankStatementId], references: [id], onDelete: Cascade)
  tenant          Tenant        @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@index([bankStatementId])
}`);

// 11. DepreciationSchedule
schema = schema.replace(
`model DepreciationSchedule {
  id             String     @id @default(uuid())
  fixedAssetId   String`,
`model DepreciationSchedule {
  id             String     @id @default(uuid())
  tenantId       String
  fixedAssetId   String`);

schema = schema.replace(
`  fixedAsset     FixedAsset @relation(fields: [fixedAssetId], references: [id], onDelete: Cascade)

  @@index([fixedAssetId])
}`,
`  fixedAsset     FixedAsset @relation(fields: [fixedAssetId], references: [id], onDelete: Cascade)
  tenant         Tenant     @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@index([fixedAssetId])
}`);

// 12. InventoryBalance
schema = schema.replace(
`model InventoryBalance {
  id          String    @id @default(uuid())
  itemId      String`,
`model InventoryBalance {
  id          String    @id @default(uuid())
  tenantId    String
  itemId      String`);

schema = schema.replace(
`  warehouse   Warehouse @relation(fields: [warehouseId], references: [id])

  @@unique([itemId, warehouseId])
}`,
`  warehouse   Warehouse @relation(fields: [warehouseId], references: [id])
  tenant      Tenant    @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@unique([itemId, warehouseId])
}`);

// 13. InventoryLot
schema = schema.replace(
`model InventoryLot {
  id          String    @id @default(uuid())
  itemId      String`,
`model InventoryLot {
  id          String    @id @default(uuid())
  tenantId    String
  itemId      String`);

schema = schema.replace(
`  warehouse   Warehouse @relation(fields: [warehouseId], references: [id])

  @@index([itemId, warehouseId])
}`,
`  warehouse   Warehouse @relation(fields: [warehouseId], references: [id])
  tenant      Tenant    @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@index([itemId, warehouseId])
}`);

// 14. Payslip
schema = schema.replace(
`model Payslip {
  id                String     @id @default(uuid())
  payrollRunId      String`,
`model Payslip {
  id                String     @id @default(uuid())
  tenantId          String
  payrollRunId      String`);

schema = schema.replace(
`  payrollRun        PayrollRun @relation(fields: [payrollRunId], references: [id], onDelete: Cascade)

  @@unique([payrollRunId, employeeProfileId])
  @@index([payrollRunId])
}`,
`  payrollRun        PayrollRun @relation(fields: [payrollRunId], references: [id], onDelete: Cascade)
  tenant            Tenant     @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@unique([payrollRunId, employeeProfileId])
  @@index([payrollRunId])
}`);

// 15. PurchaseOrderLine
schema = schema.replace(
`model PurchaseOrderLine {
  id              String        @id @default(uuid())
  purchaseOrderId String`,
`model PurchaseOrderLine {
  id              String        @id @default(uuid())
  tenantId        String
  purchaseOrderId String`);

schema = schema.replace(
`  purchaseOrder   PurchaseOrder @relation(fields: [purchaseOrderId], references: [id], onDelete: Cascade)

  @@index([purchaseOrderId])
}`,
`  purchaseOrder   PurchaseOrder @relation(fields: [purchaseOrderId], references: [id], onDelete: Cascade)
  tenant          Tenant        @relation(fields: [tenantId], references: [id])

  @@index([tenantId])
  @@index([purchaseOrderId])
}`);

// 16. EmployeeProfile isSaudi
schema = schema.replace(
`  // KSA HR fields
  hireDate       DateTime?`,
`  // KSA HR fields
  isSaudi        Boolean   @default(true)
  hireDate       DateTime?`);

// Add required back-relations for JournalEntry
schema = schema.replace(
`  lines       JournalEntryLine[]`,
`  lines       JournalEntryLine[]
  invoice                 Invoice?
  payment                 Payment?
  inventoryTransaction    InventoryTransaction?
  payrollRun              PayrollRun?`);

fs.writeFileSync('apps/api/prisma/schema.prisma', schema, 'utf8');
console.log('Schema modified successfully.');
