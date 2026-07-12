import { Controller, Get, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { ZakatService } from './zakat.service';
import { EstimateZakatDto } from './dto/estimate-zakat.dto';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';

@ApiTags('Compliance - Zakat')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('compliance/zakat')
export class ZakatController {
  constructor(private readonly zakatService: ZakatService) {}

  @Get('estimate')
  @ApiOperation({ summary: 'Estimate Zakat provision based on Trial Balance' })
  @RequirePermissions(PERMISSIONS.COMPLIANCE_ZAKAT_READ)
  async estimateZakat(
    @CurrentUser() user: any,
    @Query() dto: EstimateZakatDto,
  ) {
    return this.zakatService.estimateZakat(user.tenantId, dto);
  }
}
