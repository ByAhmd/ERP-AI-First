import { Controller, Get, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../common/decorators/current-user.decorator';
import { ReportsService } from './reports.service';

@ApiTags('Reports')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('reports')
export class ReportsController {
  constructor(private readonly reportsService: ReportsService) {}

  @Get('pnl')
  @ApiOperation({ summary: 'Generate Profit & Loss (Income Statement)' })
  @ApiQuery({ name: 'startDate', required: false, type: String })
  @ApiQuery({ name: 'endDate', required: false, type: String })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getPnl(
    @CurrentUser() user: RequestUser,
    @Query('startDate') startDate?: string,
    @Query('endDate') endDate?: string,
  ) {
    const start = startDate ? new Date(startDate) : undefined;
    const end = endDate ? new Date(endDate) : undefined;
    return this.reportsService.getPnl(user.tenantId!, start, end);
  }

  @Get('balance-sheet')
  @ApiOperation({ summary: 'Generate Balance Sheet' })
  @ApiQuery({ name: 'asOfDate', required: false, type: String })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getBalanceSheet(
    @CurrentUser() user: RequestUser,
    @Query('asOfDate') asOfDate?: string,
  ) {
    const asOf = asOfDate ? new Date(asOfDate) : new Date();
    return this.reportsService.getBalanceSheet(user.tenantId!, asOf);
  }

  @Get('cash-flow')
  @ApiOperation({ summary: 'Generate Cash Flow Statement' })
  @ApiQuery({ name: 'startDate', required: false, type: String })
  @ApiQuery({ name: 'endDate', required: false, type: String })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getCashFlow(
    @CurrentUser() user: RequestUser,
    @Query('startDate') startDate?: string,
    @Query('endDate') endDate?: string,
  ) {
    const start = startDate ? new Date(startDate) : undefined;
    const end = endDate ? new Date(endDate) : undefined;
    return this.reportsService.getCashFlow(user.tenantId!, start, end);
  }

  @Get('dashboard-kpis')
  @ApiOperation({ summary: 'Generate KPI metrics for dashboard' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getDashboardKpis(@CurrentUser() user: RequestUser) {
    return this.reportsService.getDashboardKpis(user.tenantId!);
  }
}
