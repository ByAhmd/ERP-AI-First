import { Test, TestingModule } from '@nestjs/testing';
import { ZakatService } from './zakat.service';

describe('ZakatService', () => {
  let service: ZakatService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ZakatService],
    }).compile();

    service = module.get<ZakatService>(ZakatService);
  });

  it('should be defined', () => {
    expect(service).toBeDefined();
  });
});
