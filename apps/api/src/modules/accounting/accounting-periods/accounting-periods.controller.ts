import { Controller, Get, Query } from '@nestjs/common';
import { AccountingPeriodsService } from './accounting-periods.service';

@Controller('accounting/periods')
export class AccountingPeriodsController {
  constructor(private readonly accountingPeriodsService: AccountingPeriodsService) {}

  @Get()
  listPeriods(@Query('tenantId') tenantId?: string) {
    return this.accountingPeriodsService.listPeriods(tenantId);
  }
}
