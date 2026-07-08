import { Test, TestingModule } from '@nestjs/testing';
import { EmployeeProfilesController } from './employee-profiles.controller';

describe('EmployeeProfilesController', () => {
  let controller: EmployeeProfilesController;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [EmployeeProfilesController],
    }).compile();

    controller = module.get<EmployeeProfilesController>(EmployeeProfilesController);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });
});
