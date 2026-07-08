import { Module } from '@nestjs/common';
import { EmployeeProfilesService } from './employee-profiles.service';
import { EmployeeProfilesController } from './employee-profiles.controller';

import { JournalEntriesModule } from '../../accounting/journal-entries/journal-entries.module';

@Module({
  imports: [JournalEntriesModule],
  providers: [EmployeeProfilesService],
  controllers: [EmployeeProfilesController]
})
export class EmployeeProfilesModule {}
