import { Module } from '@nestjs/common';
import { ReportsService } from './reports.service';
import { ReportsController } from './reports.controller';
import { GeneralLedgerModule } from '../accounting/general-ledger/general-ledger.module';

@Module({
  imports: [GeneralLedgerModule],
  controllers: [ReportsController],
  providers: [ReportsService],
  exports: [ReportsService],
})
export class ReportsModule {}
