import { Controller, Get, Param, Patch, Body, Query, UseGuards, Post } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { Auditable } from '../../../common/decorators/auditable.decorator';
import { AccountingPeriodsService } from './accounting-periods.service';

@ApiTags('Accounting Periods')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/periods')
export class AccountingPeriodsController {
  constructor(private readonly accountingPeriodsService: AccountingPeriodsService) {}

  @Get()
  @ApiOperation({ summary: 'List all accounting periods' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_READ)
  findAll(@CurrentUser() user: RequestUser, @Query('fiscalYearId') fiscalYearId?: string) {
    return this.accountingPeriodsService.findAllByTenant(user.tenantId!, fiscalYearId);
  }

  @Post('initialize-year')
  @ApiOperation({ summary: 'Initialize a new fiscal year and 12 periods' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_MANAGE)
  @Auditable({ action: 'CREATE', entityType: 'FiscalYear' })
  initializeFiscalYear(@CurrentUser() user: RequestUser, @Body('year') year: number) {
    return this.accountingPeriodsService.initializeFiscalYear(user.tenantId!, year);
  }

  @Patch(':id/status')
  @ApiOperation({ summary: 'Update the status of an accounting period (Open, Closed, Adjusting)' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_MANAGE)
  @Auditable({ action: 'UPDATE_STATUS', entityType: 'AccountingPeriod' })
  updateStatus(
    @CurrentUser() user: RequestUser,
    @Param('id') id: string,
    @Body('status') status: 'Open' | 'Closed' | 'Adjusting'
  ) {
    return this.accountingPeriodsService.updateStatus(user.tenantId!, id, status);
  }
}
