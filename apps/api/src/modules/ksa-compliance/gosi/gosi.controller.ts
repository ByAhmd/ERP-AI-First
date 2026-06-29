import { Controller, Get } from '@nestjs/common';
import { GosiService } from './gosi.service';

@Controller('ksa-compliance/gosi')
export class GosiController {
  constructor(private readonly gosiService: GosiService) {}

  @Get('status')
  getStatus() {
    return this.gosiService.getStatus();
  }
}
