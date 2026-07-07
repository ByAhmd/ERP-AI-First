import { Controller, Get, Post, Body, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { Auditable } from '../../../common/decorators/auditable.decorator';
import { CurrenciesService, CreateCurrencyDto, SetExchangeRateDto } from './currencies.service';

@ApiTags('Currencies')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/currencies')
export class CurrenciesController {
  constructor(private readonly currenciesService: CurrenciesService) {}

  @Post()
  @ApiOperation({ summary: 'Add a new currency to the tenant' })
  @RequirePermissions(PERMISSIONS.ADMIN_TENANT_MANAGE) // Usually an admin operation
  @Auditable({ action: 'CREATE', entityType: 'Currency' })
  create(@CurrentUser() user: RequestUser, @Body() dto: CreateCurrencyDto) {
    return this.currenciesService.create(user.tenantId!, dto);
  }

  @Get()
  @ApiOperation({ summary: 'List all currencies' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.currenciesService.findAll(user.tenantId!);
  }

  @Post(':id/exchange-rates')
  @ApiOperation({ summary: 'Set an exchange rate for a specific date' })
  @RequirePermissions(PERMISSIONS.ADMIN_SETTINGS_MANAGE)
  @Auditable({ action: 'SET_EXCHANGE_RATE', entityType: 'ExchangeRate' })
  setExchangeRate(
    @CurrentUser() user: RequestUser, 
    @Param('id') currencyId: string, 
    @Body() dto: SetExchangeRateDto
  ) {
    return this.currenciesService.setExchangeRate(user.tenantId!, currencyId, dto);
  }
}
