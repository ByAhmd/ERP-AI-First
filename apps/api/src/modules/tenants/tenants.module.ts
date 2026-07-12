import { Module } from '@nestjs/common';
import { TenantsController } from './tenants.controller';
import { TenantsService } from './tenants.service';
import { ChartOfAccountsModule } from '../accounting/chart-of-accounts/chart-of-accounts.module';

@Module({
  imports: [ChartOfAccountsModule],
  controllers: [TenantsController],
  providers: [TenantsService],
})
export class TenantsModule {}
