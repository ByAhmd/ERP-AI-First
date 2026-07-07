import { Module } from '@nestjs/common';
import { JournalEntriesController } from './journal-entries.controller';
import { JournalEntriesService } from './journal-entries.service';
import { ChartOfAccountsModule } from '../chart-of-accounts/chart-of-accounts.module';
import { AccountingPeriodsModule } from '../accounting-periods/accounting-periods.module';

@Module({
  imports: [ChartOfAccountsModule, AccountingPeriodsModule],
  controllers: [JournalEntriesController],
  providers: [JournalEntriesService],
  exports: [JournalEntriesService],
})
export class JournalEntriesModule {}
