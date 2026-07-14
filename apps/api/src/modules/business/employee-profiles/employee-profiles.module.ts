import { Module } from '@nestjs/common';
import { EmployeeProfilesService } from './employee-profiles.service';
import { EmployeeProfilesController } from './employee-profiles.controller';
import { LeaveService } from './leave.service';
import { EosbService } from './eosb.service';
import { JournalEntriesModule } from '../../accounting/journal-entries/journal-entries.module';

@Module({
  imports: [JournalEntriesModule],
  providers: [EmployeeProfilesService, LeaveService, EosbService],
  controllers: [EmployeeProfilesController],
  exports: [EmployeeProfilesService, LeaveService, EosbService]
})
export class EmployeeProfilesModule {}
