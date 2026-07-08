import { Module } from '@nestjs/common';
import { EmployeeProfilesService } from './employee-profiles.service';
import { EmployeeProfilesController } from './employee-profiles.controller';

@Module({
  providers: [EmployeeProfilesService],
  controllers: [EmployeeProfilesController]
})
export class EmployeeProfilesModule {}
