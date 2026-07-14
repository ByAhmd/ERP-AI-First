import { Controller, Post, Param, UseGuards } from '@nestjs/common';
import { ZatcaService } from './zatca.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';

@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/invoices/:invoiceId/zatca')
export class ZatcaController {
  constructor(private readonly zatcaService: ZatcaService) {}

  @Post('generate')
  async generateZatcaData(@CurrentUser() user: any, @Param('invoiceId') invoiceId: string) {
    return this.zatcaService.generateZatcaData(user.tenantId, invoiceId);
  }

  @Post('submit')
  async submitToZatca(@CurrentUser() user: any, @Param('invoiceId') invoiceId: string) {
    return this.zatcaService.submitToZatca(user.tenantId, invoiceId);
  }
}
