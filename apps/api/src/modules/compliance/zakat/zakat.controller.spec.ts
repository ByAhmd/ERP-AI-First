import { Test, TestingModule } from '@nestjs/testing';
import { ZakatController } from './zakat.controller';

describe('ZakatController', () => {
  let controller: ZakatController;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ZakatController],
    }).compile();

    controller = module.get<ZakatController>(ZakatController);
  });

  it('should be defined', () => {
    expect(controller).toBeDefined();
  });
});
