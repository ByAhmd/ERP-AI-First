import { Controller, Post, Body, Get, Query } from '@nestjs/common';
import { ConsolidationService } from './consolidation.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { SetPeriodExchangeRateDto } from './dto/consolidation.dto';

@Controller('accounting/consolidation')
export class ConsolidationController {
  constructor(private readonly consolidationService: ConsolidationService) {}

  @Post('exchange-rates')
  async setExchangeRate(
    @CurrentUser() user: any,
    @Body() dto: SetPeriodExchangeRateDto,
  ) {
    return this.consolidationService.setPeriodExchangeRate(user.tenantId, dto);
  }

  @Get('trial-balance')
  async getConsolidatedTrialBalance(
    @CurrentUser() user: any,
    @Query('startDate') startDate: string,
    @Query('endDate') endDate: string,
  ) {
    return this.consolidationService.getConsolidatedTrialBalance(user.tenantId, startDate, endDate);
  }
}
