with open('apps/api/prisma/schema.prisma', 'r') as f:
    lines = f.readlines()

new_lines = []
current_model = None
for i, line in enumerate(lines):
    if line.startswith('model '):
        current_model = line.split()[1]
    
    # 1. RolePermission
    if current_model == 'RolePermission':
        if 'id           String     @id @default(uuid())' in line:
            new_lines.append(line)
            new_lines.append('  tenantId     String\n')
            continue
        if 'role         Role       @relation(fields: [roleId], references: [id])' in line:
            new_lines.append('  tenant       Tenant     @relation(fields: [tenantId], references: [id])\n')
            new_lines.append('  role         Role       @relation(fields: [roleId], references: [id], onDelete: Cascade)\n')
            continue
        if 'permission   Permission @relation(fields: [permissionId], references: [id])' in line:
            new_lines.append('  permission   Permission @relation(fields: [permissionId], references: [id], onDelete: Cascade)\n')
            continue
        if '@@unique([roleId, permissionId])' in line:
            new_lines.append(line)
            new_lines.append('  @@index([tenantId])\n')
            continue

    # 2. JournalEntryLine
    elif current_model == 'JournalEntryLine':
        if 'journalEntry     JournalEntry    @relation(fields: [journalEntryId], references: [id])' in line:
            new_lines.append('  journalEntry     JournalEntry    @relation(fields: [journalEntryId], references: [id], onDelete: Cascade)\n')
            continue
        if 'contact          Contact?        @relation(fields: [contactId], references: [id])' in line:
            new_lines.append('  contact          Contact?        @relation(fields: [contactId], references: [id], onDelete: Restrict)\n')
            continue

    # 3. Invoice
    elif current_model == 'Invoice':
        if 'contact        Contact             @relation(fields: [contactId], references: [id])' in line:
            new_lines.append('  contact        Contact             @relation(fields: [contactId], references: [id], onDelete: Restrict)\n')
            new_lines.append('  journalEntry   JournalEntry?       @relation(fields: [journalEntryId], references: [id])\n')
            continue

    # 4. InvoiceLine
    elif current_model == 'InvoiceLine':
        if 'item        Item?      @relation(fields: [itemId], references: [id])' in line:
            new_lines.append('  item        Item?      @relation(fields: [itemId], references: [id], onDelete: Restrict)\n')
            continue
        if 'warehouse   Warehouse? @relation(fields: [warehouseId], references: [id])' in line:
            new_lines.append('  warehouse   Warehouse? @relation(fields: [warehouseId], references: [id], onDelete: Restrict)\n')
            new_lines.append('  account     ChartOfAccount @relation(fields: [accountId], references: [id])\n')
            new_lines.append('  taxCode     TaxCode?       @relation(fields: [taxCodeId], references: [id])\n')
            continue

    # 5. Payment
    elif current_model == 'Payment':
        if 'contact        Contact             @relation(fields: [contactId], references: [id])' in line:
            new_lines.append('  contact        Contact             @relation(fields: [contactId], references: [id], onDelete: Restrict)\n')
            continue
        if 'allocations    PaymentAllocation[]' in line:
            new_lines.append(line)
            new_lines.append('  accountRef     ChartOfAccount      @relation("PaymentAccount", fields: [accountId], references: [id])\n')
            new_lines.append('  whtAccountRef  ChartOfAccount?     @relation("PaymentWhtAccount", fields: [whtAccountId], references: [id])\n')
            new_lines.append('  journalEntry   JournalEntry?       @relation(fields: [journalEntryId], references: [id])\n')
            continue

    # 6. FixedAsset
    elif current_model == 'FixedAsset':
        if 'schedules             DepreciationSchedule[]' in line:
            new_lines.append(line)
            new_lines.append('  assetAccount          ChartOfAccount       @relation("AssetAccount", fields: [assetAccountId], references: [id])\n')
            new_lines.append('  depreciationAccount   ChartOfAccount       @relation("DepreciationAccount", fields: [depreciationAccountId], references: [id])\n')
            new_lines.append('  expenseAccount        ChartOfAccount       @relation("ExpenseAccount", fields: [expenseAccountId], references: [id])\n')
            continue

    # 7. Item
    elif current_model == 'Item':
        if 'InventoryTransaction InventoryTransaction[]' in line:
            new_lines.append(line)
            new_lines.append('  inventoryAccount     ChartOfAccount?        @relation("InventoryAccount", fields: [inventoryAccountId], references: [id])\n')
            new_lines.append('  cogsAccount          ChartOfAccount?        @relation("CogsAccount", fields: [cogsAccountId], references: [id])\n')
            new_lines.append('  revenueAccount       ChartOfAccount?        @relation("RevenueAccount", fields: [revenueAccountId], references: [id])\n')
            continue

    # 8. InventoryTransaction
    elif current_model == 'InventoryTransaction':
        if 'warehouse      Warehouse                @relation(fields: [warehouseId], references: [id])' in line:
            new_lines.append(line)
            new_lines.append('  journalEntry   JournalEntry?            @relation(fields: [journalEntryId], references: [id])\n')
            continue

    # 9. PayrollRun
    elif current_model == 'PayrollRun':
        if 'payslips              Payslip[]' in line:
            new_lines.append(line)
            new_lines.append('  journalEntry          JournalEntry? @relation(fields: [journalEntryId], references: [id])\n')
            continue

    # 10. BankStatementTransaction
    elif current_model == 'BankStatementTransaction':
        if 'bankStatementId String' in line:
            new_lines.append('  tenantId        String\n')
            new_lines.append(line)
            continue
        if 'bankStatement   BankStatement @relation(fields: [bankStatementId], references: [id], onDelete: Cascade)' in line:
            new_lines.append(line)
            new_lines.append('  tenant          Tenant        @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@index([bankStatementId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 11. DepreciationSchedule
    elif current_model == 'DepreciationSchedule':
        if 'fixedAssetId   String' in line:
            new_lines.append('  tenantId       String\n')
            new_lines.append(line)
            continue
        if 'fixedAsset     FixedAsset @relation(fields: [fixedAssetId], references: [id], onDelete: Cascade)' in line:
            new_lines.append(line)
            new_lines.append('  tenant         Tenant     @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@index([fixedAssetId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 12. InventoryBalance
    elif current_model == 'InventoryBalance':
        if 'itemId      String' in line:
            new_lines.append('  tenantId    String\n')
            new_lines.append(line)
            continue
        if 'warehouse   Warehouse @relation(fields: [warehouseId], references: [id])' in line:
            new_lines.append(line)
            new_lines.append('  tenant      Tenant    @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@unique([itemId, warehouseId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 13. InventoryLot
    elif current_model == 'InventoryLot':
        if 'itemId      String' in line:
            new_lines.append('  tenantId    String\n')
            new_lines.append(line)
            continue
        if 'warehouse   Warehouse @relation(fields: [warehouseId], references: [id])' in line:
            new_lines.append(line)
            new_lines.append('  tenant      Tenant    @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@index([itemId, warehouseId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 14. Payslip
    elif current_model == 'Payslip':
        if 'payrollRunId      String' in line:
            new_lines.append('  tenantId          String\n')
            new_lines.append(line)
            continue
        if 'payrollRun        PayrollRun @relation(fields: [payrollRunId], references: [id], onDelete: Cascade)' in line:
            new_lines.append(line)
            new_lines.append('  tenant            Tenant     @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@unique([payrollRunId, employeeProfileId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 15. PurchaseOrderLine
    elif current_model == 'PurchaseOrderLine':
        if 'purchaseOrderId String' in line:
            new_lines.append('  tenantId        String\n')
            new_lines.append(line)
            continue
        if 'purchaseOrder   PurchaseOrder @relation(fields: [purchaseOrderId], references: [id], onDelete: Cascade)' in line:
            new_lines.append(line)
            new_lines.append('  tenant          Tenant        @relation(fields: [tenantId], references: [id])\n')
            continue
        if '@@index([purchaseOrderId])' in line:
            new_lines.append('  @@index([tenantId])\n')
            new_lines.append(line)
            continue

    # 16. EmployeeProfile
    elif current_model == 'EmployeeProfile':
        if 'hireDate       DateTime?' in line:
            new_lines.append('  isSaudi        Boolean   @default(true)\n')
            new_lines.append(line)
            continue

    # 17. JournalEntry
    elif current_model == 'JournalEntry':
        if 'lines       JournalEntryLine[]' in line:
            new_lines.append(line)
            new_lines.append('  invoice                 Invoice?\n')
            new_lines.append('  payment                 Payment?\n')
            new_lines.append('  inventoryTransaction    InventoryTransaction?\n')
            new_lines.append('  payrollRun              PayrollRun?\n')
            continue

    new_lines.append(line)

with open('apps/api/prisma/schema.prisma', 'w') as f:
    f.writelines(new_lines)
