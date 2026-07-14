import { Controller, Post, Get, Body, Param, ParseUUIDPipe, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { BankReconciliationService } from './bank-reconciliation.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { UploadStatementDto } from './dto/bank-reconciliation.dto';

@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('accounting/reconciliation')
export class BankReconciliationController {
  constructor(private readonly bankReconciliationService: BankReconciliationService) {}

  @Get()
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_READ)
  async getReconciliations(@CurrentUser() user: any) {
    return this.bankReconciliationService.getReconciliations(user.tenantId);
  }

  @Get('account/:accountId/unreconciled')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_READ)
  async getUnreconciledLines(
    @CurrentUser() user: any,
    @Param('accountId', ParseUUIDPipe) accountId: string,
  ) {
    return this.bankReconciliationService.getUnreconciledLines(user.tenantId, accountId);
  }

  @Get(':id')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_READ)
  async getReconciliation(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) id: string,
  ) {
    return this.bankReconciliationService.getReconciliation(user.tenantId, id);
  }

  @Post('statement')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_WRITE)
  async uploadBankStatement(
    @CurrentUser() user: any,
    @Body() dto: UploadStatementDto,
  ) {
    return this.bankReconciliationService.uploadStatement(user.tenantId, dto);
  }

  @Post(':id/auto-match')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_WRITE)
  async autoMatch(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
  ) {
    return this.bankReconciliationService.autoMatch(user.tenantId, reconciliationId);
  }

  @Post(':id/manual-match')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_WRITE)
  async manualMatch(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
    @Body() body: { journalLineIds: string[] },
  ) {
    return this.bankReconciliationService.manualMatch(user.tenantId, reconciliationId, body.journalLineIds);
  }

  @Post(':id/manual-unmatch')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_WRITE)
  async manualUnmatch(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
    @Body() body: { journalLineIds: string[] },
  ) {
    return this.bankReconciliationService.manualUnmatch(user.tenantId, reconciliationId, body.journalLineIds);
  }

  @Post(':id/reconcile')
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_WRITE)
  async completeReconciliation(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
  ) {
    return this.bankReconciliationService.completeReconciliation(user.tenantId, reconciliationId, user.id);
  }
}
