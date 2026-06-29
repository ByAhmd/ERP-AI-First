import { Controller, Get } from '@nestjs/common';
import { WhtService } from './wht.service';

@Controller('ksa-compliance/wht')
export class WhtController {
  constructor(private readonly whtService: WhtService) {}

  @Get('status')
  getStatus() {
    return this.whtService.getStatus();
  }
}
