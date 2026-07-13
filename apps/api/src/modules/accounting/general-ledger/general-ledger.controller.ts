import { Controller, Get, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { GeneralLedgerService } from './general-ledger.service';

@ApiTags('General Ledger')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/gl')
export class GeneralLedgerController {
  constructor(private readonly glService: GeneralLedgerService) {}

  @Get('trial-balance')
  @ApiOperation({ summary: 'Generate a Trial Balance report' })
  @ApiQuery({ name: 'startDate', required: false, type: String })
  @ApiQuery({ name: 'endDate', required: false, type: String })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getTrialBalance(
    @CurrentUser() user: RequestUser,
    @Query('startDate') startDate?: string,
    @Query('endDate') endDate?: string,
  ) {
    const start = startDate ? new Date(startDate) : undefined;
    const end = endDate ? new Date(endDate) : undefined;
    return this.glService.getTrialBalance(user.tenantId!, start, end);
  }

  @Get('transactions')
  @ApiOperation({ summary: 'Get all General Ledger transactions' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_REPORTS_READ)
  getTransactions(@CurrentUser() user: RequestUser) {
    return this.glService.getTransactions(user.tenantId!);
  }
}
