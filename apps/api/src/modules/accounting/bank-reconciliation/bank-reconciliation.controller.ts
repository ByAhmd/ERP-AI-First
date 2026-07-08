import { Controller, Post, Body, Param, ParseUUIDPipe } from '@nestjs/common';
import { BankReconciliationService } from './bank-reconciliation.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { UploadStatementDto } from './dto/bank-reconciliation.dto';

@Controller('accounting/reconciliation')
export class BankReconciliationController {
  constructor(private readonly bankReconciliationService: BankReconciliationService) {}

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
