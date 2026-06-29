import { Body, Controller, Get, Post, Query } from '@nestjs/common';
import { CreateChartOfAccountDto } from './dto/create-chart-of-account.dto';
import { ChartOfAccountsService } from './chart-of-accounts.service';

@Controller('accounting/chart-of-accounts')
export class ChartOfAccountsController {
  constructor(private readonly chartOfAccountsService: ChartOfAccountsService) {}

  @Post()
  createAccount(@Body() dto: CreateChartOfAccountDto) {
    return this.chartOfAccountsService.createAccount(dto);
  }

  @Get()
  listAccounts(@Query('tenantId') tenantId: string) {
    return this.chartOfAccountsService.listAccounts(tenantId);
  }
}
