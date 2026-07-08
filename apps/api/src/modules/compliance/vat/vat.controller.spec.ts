import { Test, TestingModule } from '@nestjs/testing';
import { VatController } from './vat.controller';

describe('VatController', () => {
  let controller: VatController;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [VatController],
    }).compile();

    controller = module.get<VatController>(VatController);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });
});
