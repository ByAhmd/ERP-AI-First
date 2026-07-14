import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { APP_INTERCEPTOR } from '@nestjs/core';
import { ThrottlerModule } from '@nestjs/throttler';
import { appConfig } from './config/app.config';
import { validateEnv } from './config/env.validation';
import { ThrottlerConfigService } from './config/throttler.config';
import { DatabaseModule } from './database/database.module';
import { RedisModule } from './database/redis.module';
import { AuditInterceptor } from './common/interceptors/audit.interceptor';
import { AccountingPeriodsModule } from './modules/accounting/accounting-periods/accounting-periods.module';
import { ChartOfAccountsModule } from './modules/accounting/chart-of-accounts/chart-of-accounts.module';
import { JournalEntriesModule } from './modules/accounting/journal-entries/journal-entries.module';
import { FiscalYearsModule } from './modules/accounting/fiscal-years/fiscal-years.module';
import { GeneralLedgerModule } from './modules/accounting/general-ledger/general-ledger.module';
import { CurrenciesModule } from './modules/accounting/currencies/currencies.module';
import { SequencesModule } from './modules/business/sequences/sequences.module';
import { ContactsModule } from './modules/business/contacts/contacts.module';
import { InvoicesModule } from './modules/business/invoices/invoices.module';
import { PaymentsModule } from './modules/business/payments/payments.module';
import { EmployeeProfilesModule } from './modules/business/employee-profiles/employee-profiles.module';
import { AuditLogsModule } from './modules/audit-logs/audit-logs.module';
import { AuthModule } from './modules/auth/auth.module';
import { HealthModule } from './modules/health/health.module';
import { PermissionsModule } from './modules/permissions/permissions.module';
import { RolesModule } from './modules/roles/roles.module';
import { TenantsModule } from './modules/tenants/tenants.module';
import { UsersModule } from './modules/users/users.module';

import { VatModule } from './modules/compliance/vat/vat.module';
import { ZakatModule } from './modules/compliance/zakat/zakat.module';
import { DateModule } from './modules/compliance/date/date.module';
// Keeping other generated KSA compliance shells
import { GosiModule } from './modules/ksa-compliance/gosi/gosi.module';
import { WhtModule } from './modules/ksa-compliance/wht/wht.module';
import { WpsModule } from './modules/ksa-compliance/wps/wps.module';
import { ZatcaModule } from './modules/ksa-compliance/zatca/zatca.module';

import { BankReconciliationModule } from './modules/accounting/bank-reconciliation/bank-reconciliation.module';

import { FixedAssetsModule } from './modules/accounting/fixed-assets/fixed-assets.module';

import { InventoryModule } from './modules/accounting/inventory/inventory.module';

import { ConsolidationModule } from './modules/accounting/consolidation/consolidation.module';

import { PayrollModule } from './modules/business/payroll/payroll.module';
import { ReportsModule } from './modules/reports/reports.module';
import { ProcurementModule } from './modules/business/procurement/procurement.module';
import { CrmModule } from './modules/business/crm/crm.module';
import { ApprovalsModule } from './modules/approvals/approvals.module';
import { EventEmitterModule } from '@nestjs/event-emitter';

@Module({
  imports: [
    EventEmitterModule.forRoot(),
    ConfigModule.forRoot({
      isGlobal: true,
      validate: validateEnv,
      load: [appConfig],
    }),
    ThrottlerModule.forRootAsync({
      useClass: ThrottlerConfigService,
    }),
    DatabaseModule,
    RedisModule,
    HealthModule,
    TenantsModule,
    UsersModule,
    AuthModule,
    RolesModule,
    PermissionsModule,
    AuditLogsModule,
    FiscalYearsModule,
    AccountingPeriodsModule,
    ChartOfAccountsModule,
    JournalEntriesModule,
    GeneralLedgerModule,
    CurrenciesModule,
    BankReconciliationModule,
    FixedAssetsModule,
    InventoryModule,
    ConsolidationModule,
    SequencesModule,
    ContactsModule,
    InvoicesModule,
    PaymentsModule,
    EmployeeProfilesModule,
    PayrollModule,
    ReportsModule,
    ProcurementModule,
    CrmModule,
    ApprovalsModule,
    VatModule,
    ZakatModule,
    DateModule,
    GosiModule,
    WhtModule,
    WpsModule,
    ZatcaModule,
  ],
  providers: [
    {
      provide: APP_INTERCEPTOR,
      useClass: AuditInterceptor,
    },
  ],
})
export class AppModule {}
