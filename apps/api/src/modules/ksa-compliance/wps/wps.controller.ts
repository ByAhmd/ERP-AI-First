import { Controller, Get } from '@nestjs/common';
import { WpsService } from './wps.service';

@Controller('ksa-compliance/wps')
export class WpsController {
  constructor(private readonly wpsService: WpsService) {}

  @Get('status')
  getStatus() {
    return this.wpsService.getStatus();
  }
}
