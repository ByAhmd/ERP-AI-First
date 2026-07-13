import { Controller, Post, Get, Body, Param, ParseUUIDPipe, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { BankReconciliationService } from './bank-reconciliation.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { UploadStatementDto } from './dto/bank-reconciliation.dto';

@UseGuards(JwtAuthGuard)
@Controller('accounting/reconciliation')
export class BankReconciliationController {
  constructor(private readonly bankReconciliationService: BankReconciliationService) {}

  @Get()
  async getReconciliations(@CurrentUser() user: any) {
    return this.bankReconciliationService.getReconciliations(user.tenantId);
  }

  @Get(':id')
  async getReconciliation(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) id: string,
  ) {
    return this.bankReconciliationService.getReconciliation(user.tenantId, id);
  }

  @Post('statement')
  // We can add a permission for reconciliation later, for now just use a basic one or omit
  async uploadBankStatement(
    @CurrentUser() user: any,
    @Body() dto: UploadStatementDto,
  ) {
    return this.bankReconciliationService.uploadStatement(user.tenantId, dto);
  }

  @Post(':id/auto-match')
  async autoMatch(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
  ) {
    return this.bankReconciliationService.autoMatch(user.tenantId, reconciliationId);
  }

  @Post(':id/reconcile')
  async completeReconciliation(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) reconciliationId: string,
  ) {
    return this.bankReconciliationService.completeReconciliation(user.tenantId, reconciliationId);
  }
}
