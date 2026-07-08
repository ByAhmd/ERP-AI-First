import { Test, TestingModule } from '@nestjs/testing';
import { VatService } from './vat.service';

describe('VatService', () => {
  let service: VatService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [VatService],
    }).compile();

    service = module.get<VatService>(VatService);
  });

  it('should be defined', () => {
    expect(service).toBeDefined();
  });
});
