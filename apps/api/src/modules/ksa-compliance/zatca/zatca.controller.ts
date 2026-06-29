import { Controller, Get } from '@nestjs/common';
import { ZatcaService } from './zatca.service';

@Controller('ksa-compliance/zatca')
export class ZatcaController {
  constructor(private readonly zatcaService: ZatcaService) {}

  @Get('status')
  getStatus() {
    return this.zatcaService.getStatus();
  }
}
