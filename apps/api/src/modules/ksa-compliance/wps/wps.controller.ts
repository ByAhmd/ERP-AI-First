import { Controller, Get, Param, Query, Header, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { WpsService } from './wps.service';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';

@ApiTags('Compliance - WPS')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('ksa-compliance/wps')
export class WpsController {
  constructor(private readonly wpsService: WpsService) {}

  @Get('sif/:payrollRunId')
  @RequirePermissions(PERMISSIONS.COMPLIANCE_WPS_MANAGE)
  @Header('Content-Type', 'text/csv')
  @Header('Content-Disposition', 'attachment; filename="sif.csv"')
  async generateSif(
    @CurrentUser() user: RequestUser,
    @Param('payrollRunId') payrollRunId: string
  ) {
    return this.wpsService.generateSif(user.tenantId!, payrollRunId);
  }

  @Get('status')
  @RequirePermissions(PERMISSIONS.COMPLIANCE_WPS_READ)
  getStatus() {
    return this.wpsService.getStatus();
  }
}
