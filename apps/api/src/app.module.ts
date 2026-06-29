import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { DatabaseModule } from './database/database.module';
import { AccountingPeriodsModule } from './modules/accounting/accounting-periods/accounting-periods.module';
import { ChartOfAccountsModule } from './modules/accounting/chart-of-accounts/chart-of-accounts.module';
import { JournalEntriesModule } from './modules/accounting/journal-entries/journal-entries.module';
import { AuditLogsModule } from './modules/audit-logs/audit-logs.module';
import { AuthModule } from './modules/auth/auth.module';
import { HealthModule } from './modules/health/health.module';
import { GosiModule } from './modules/ksa-compliance/gosi/gosi.module';
import { VatModule } from './modules/ksa-compliance/vat/vat.module';
import { WhtModule } from './modules/ksa-compliance/wht/wht.module';
import { WpsModule } from './modules/ksa-compliance/wps/wps.module';
import { ZakatModule } from './modules/ksa-compliance/zakat/zakat.module';
import { ZatcaModule } from './modules/ksa-compliance/zatca/zatca.module';
import { PermissionsModule } from './modules/permissions/permissions.module';
import { RolesModule } from './modules/roles/roles.module';
import { TenantsModule } from './modules/tenants/tenants.module';
import { UsersModule } from './modules/users/users.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    DatabaseModule,
    HealthModule,
    TenantsModule,
    UsersModule,
    AuthModule,
    RolesModule,
    PermissionsModule,
    AuditLogsModule,
    ChartOfAccountsModule,
    JournalEntriesModule,
    AccountingPeriodsModule,
    VatModule,
    ZatcaModule,
    ZakatModule,
    WhtModule,
    GosiModule,
    WpsModule,
  ],
})
export class AppModule {}
