import { Controller, Get } from '@nestjs/common';
import { ZakatService } from './zakat.service';

@Controller('ksa-compliance/zakat')
export class ZakatController {
  constructor(private readonly zakatService: ZakatService) {}

  @Get('status')
  getStatus() {
    return this.zakatService.getStatus();
  }
}
