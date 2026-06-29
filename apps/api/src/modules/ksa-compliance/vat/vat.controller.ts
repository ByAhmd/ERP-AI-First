import { Controller, Get } from '@nestjs/common';
import { VatService } from './vat.service';

@Controller('ksa-compliance/vat')
export class VatController {
  constructor(private readonly vatService: VatService) {}

  @Get('status')
  getStatus() {
    return this.vatService.getStatus();
  }
}
