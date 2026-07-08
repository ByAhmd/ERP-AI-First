import { Test, TestingModule } from '@nestjs/testing';
import { EmployeeProfilesService } from './employee-profiles.service';

describe('EmployeeProfilesService', () => {
  let service: EmployeeProfilesService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [EmployeeProfilesService],
    }).compile();

    service = module.get<EmployeeProfilesService>(EmployeeProfilesService);
  });

  it('should be defined', () => {
    expect(service).toBeDefined();
  });
});
