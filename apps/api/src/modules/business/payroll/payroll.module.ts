import { Module } from '@nestjs/common';
import { PayrollController } from './payroll.controller';
import { PayrollService } from './payroll.service';
import { JournalEntriesModule } from '../../accounting/journal-entries/journal-entries.module';

@Module({
  imports: [JournalEntriesModule],
  controllers: [PayrollController],
  providers: [PayrollService],
  exports: [PayrollService],
})
export class PayrollModule {}
