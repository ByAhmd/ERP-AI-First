import { Controller, Get, Param, Query, Header } from '@nestjs/common';
import { WpsService } from './wps.service';

@Controller('ksa-compliance/wps')
export class WpsController {
  constructor(private readonly wpsService: WpsService) {}

  @Get('sif/:payrollRunId')
  @Header('Content-Type', 'text/csv')
  @Header('Content-Disposition', 'attachment; filename="sif.csv"')
  async generateSif(
    @Query('tenantId') tenantId: string,
    @Param('payrollRunId') payrollRunId: string
  ) {
    if (!tenantId) {
      throw new Error('tenantId is required');
    }
    return this.wpsService.generateSif(tenantId, payrollRunId);
  }

  @Get('status')
  getStatus() {
    return this.wpsService.getStatus();
  }
}
